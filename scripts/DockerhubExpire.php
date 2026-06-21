<?php

/**
 * Used by Github action to remove old images from Docker Hub.
 *
 * Removes old images from Docker Hub repositories based on simple rules.
 * Inspired by https://github.com/lostlink/docker-cleanup.
 */
class DockerhubExpire {

  /**
   * DockerHub user name.
   *
   * @var string
   */
  protected string $username;

  /**
   * DockerHub password or personal access token.
   *
   * @var string
   */
  protected string $password;

  /**
   * TRUE for dry run, no changes will be made.
   *
   * @var bool
   */
  protected bool $dryRun;

  /**
   * TRUE to output GitHub markup for a summary.
   *
   * @var bool
   */
  protected bool $emitMarkup;

  /**
   * List of regex patterns defining protection rules.
   *
   * A rule consists of a regex and an exipration date in days.
   * A NULL value for the days indicates protection for an infinite time.
   * For example, ['abc' => 7, '^def' => 30, 'latest' => NULL].
   * If multiple patterns match, NULL takes priority, and thereafter the
   * highest day value takes precedence.
   * At least one rule is required, otherwise nothing would be modified.
   *
   * @var array
   */
  protected array $protectionRules;

  /**
   * The DockerHub API address.
   *
   * @var string
   */
  protected string $hubUrl = 'https://hub.docker.com/v2';

  /**
   * The DockerHub authorization URL.
   *
   * @var string
   */
  protected string $authUrl = 'https://auth.docker.io';

  /**
   * The DockerHub registry URL.
   *
   * @var string
   */
  protected string $registryUrl = 'https://registry-1.docker.io/v2';

  /**
   * Tokens for DockerHub, keyed by "$namespace/$repo".
   *
   * @var array
   */
  protected array $tokens = [];

  /**
   * Timeout value used for curl requests.
   *
   * @var int
   */
  protected int $timeout = 30;

  /**
   * Stores the run summary.
   *
   * @var string
   */
  public string $summary = '';

  /**
   * Class constructor, stores parameters in class variables.
   *
   * @param string $username
   *   DockerHub user name.
   * @param string $password
   *   DockerHub password or personal access token.
   * @param string $protectionRules
   *   One or more regex with expiration in days.
   * @param bool $emitMarkup
   *   If TRUE, output a summary in github markup format to stdout.
   * @param bool $dryRun
   *   If TRUE, no changes will be made.
   */
  public function __construct(
    string $username,
    string $password,
    string $protectionRules,
    bool $emitMarkup = FALSE,
    bool $dryRun = TRUE,
  ) {
    $this->username = $username;
    $this->password = $password;
    $this->parseprotectionRules($protectionRules);
    $this->emitMarkup = $emitMarkup;
    $this->dryRun = $dryRun;
  }

  /**
   * Validates and parses the protectionRules parameter.
   *
   * @param string $patterns
   *   A string containing multiple regex patterns and ages in the format
   *   'abc:7, ^def\d+:30, ghi'.
   *   If a colon + day value is not specified, NULL is stored which indicates
   *   protection for infinite time.
   *
   * @return void
   *   Stores to $this->protectionRules.
   */
  protected function parseprotectionRules(string $patterns): void {
    $items = preg_split('/, */', $patterns);
    foreach ($items as $item) {
      $parts = preg_split('/: */', $item);
      if (count($parts) > 2) {
        throw new Exception("Invalid pattern for protection-rules, a single colon expected: \"$item\"");
      }
      $this->protectionRules[$parts[0]] = $parts[1] ?? NULL;
    }
  }

  /**
   * Outputs a log message to STDERR with timestamp.
   *
   * @param string $msg
   *   The message to be output.
   *
   * @return void
   *   No return value.
   */
  protected function log(string $msg): void {
    $timeStamp = gmdate('Y-m-d H:i:s') . ' UTC';
    fwrite(STDERR, "[$timeStamp] $msg\n");
  }

  /**
   * Encodes username and password to use for basic authentication.
   *
   * @return string
   *   The encoded string.
   */
  protected function basicAuth(): string {
    return 'Basic ' . base64_encode($this->username . ':' . $this->password);
  }

  /**
   * Use CURL to make an HTTP request.
   *
   * @param string $method
   *   Here only 'GET' or 'DELETE' is used.
   * @param string $url
   *   URL the request will be sent to.
   * @param array $headers
   *   Request headers.
   * @param mixed $body
   *   Data payload sent in the request.
   *
   * @return array
   *   Array of two values, curl return code, and curl response content.
   */
  protected function httpRequest(string $method, string $url, array $headers = [], mixed $body = NULL): array {
    $curlHandle = curl_init($url);

    curl_setopt_array($curlHandle, [
      CURLOPT_RETURNTRANSFER => TRUE,
      CURLOPT_TIMEOUT => $this->timeout,
      CURLOPT_CUSTOMREQUEST => $method,
      CURLOPT_HTTPHEADER => $headers,
    ]);
    if ($body) {
      curl_setopt($curlHandle, CURLOPT_POSTFIELDS, $body);
    }

    $response = curl_exec($curlHandle);
    $code = curl_getinfo($curlHandle, CURLINFO_HTTP_CODE);

    if ($response === FALSE) {
      throw new Exception(curl_error($curlHandle));
    }

    return [$code, $response];
  }

  /**
   * Retrieve a bearer token for a specific repository.
   *
   * @param string $namespace
   *   Repository namespace.
   * @param string $repo
   *   Repository name within the namespace.
   *
   * @return string
   *   The bearer token, which is also cached in $this->tokens.
   */
  protected function getBearerToken(string $namespace, string $repo): string {
    $key = "$namespace/$repo";

    // Use cached token if available.
    if (isset($this->tokens[$key])) {
      return $this->tokens[$key];
    }

    // Request a new token.
    $url = $this->authUrl . "/token?service=registry.docker.io&scope=repository:$namespace/$repo:pull,push,delete";

    [$code, $response] = $this->httpRequest('GET', $url, [
      'Authorization: ' . $this->basicAuth(),
    ]);

    if ($code !== 200) {
      throw new Exception('Failed to get token, error code ' . $code);
    }

    $data = json_decode($response, TRUE);
    $token = $data['token'];

    $this->tokens[$key] = $token;
    return $token;
  }

  /**
   * Retrieve all image tags from a DockerHub repository, indexed by digest.
   *
   * Index tags by digest sha so we can evaluate all tags on a
   * given digest and evaluate for the longest-lived tag.
   *
   * @param string $namespace
   *   Repository namespace.
   * @param string $repo
   *   Repository name within the namespace.
   *
   * @return array
   *   The list of digests and within each digest the tags for that digest.
   */
  public function getDigestTags(string $namespace, string $repo): array {
    $digests = [];
    $ntags = 0;
    $page = 1;
    $pageSize = 15;
    while (TRUE) {
      $url = $this->hubUrl . '/repositories/' . $namespace . '/' . $repo . '/tags?page=' . $page . '&page_size=' . $pageSize;
      [$code, $response] = $this->httpRequest('GET', $url, [
        'Authorization: ' . $this->basicAuth(),
      ]);
      if ($code !== 200) {
        $this->log('Error, failed to get digest tags for "' . $namespace . '/' . $repo . '", error code: ' . $code);
        return [];
      }

      $data = json_decode($response, TRUE);
      $tags = $data['results'] ?? [];
      if (!$tags) {
        break;
      }

      // Stores in an array indexed by digest sha as the first key.
      foreach ($tags as $tag) {
        $digest_id = $tag['images'][0]['digest'];
        $digests[$digest_id][] = $tag;
        $ntags++;
      }

      // Decide if the loop has retrieved all tags.
      // If by chance the last page is exactly full, we will do one
      // more fetch, and the next page will then have zero tags.
      if (count($tags) < $pageSize) {
        break;
      }
      $page++;
    }

    $this->log('Retrieved ' . count($digests) . " digests and $ntags tags");
    return $digests;
  }

  /**
   * Deletes the specified image digest.
   *
   * Dockerhub will automatically delete all tags referencing this digest.
   *
   * @param string $namespace
   *   Repository namespace.
   * @param string $repo
   *   Repository name within the namespace.
   * @param string $digest
   *   The sha digest specifying the image to delete.
   * @param string $tags
   *   The corresponding imploded tags. Only used for the log message.
   *
   * @return bool
   *   Returns TRUE for success.
   *   Returns FALSE if an error occurred.
   *   Dry run returns TRUE but nothing is deleted.
   */
  public function deleteDigest(string $namespace, string $repo, string $digest, string $tags): bool {

    $token = $this->getBearerToken($namespace, $repo);
    $short_sha = substr($digest, 7, 7);
    if ($this->dryRun) {
      $this->log("[DRY RUN] Would delete $short_sha $repo:$tags");
      return TRUE;
    }

    // The delete request.
    [$code2, $response2] = $this->httpRequest('DELETE',
      $this->registryUrl . '/' . $namespace . '/' . $repo . '/manifests/' . $digest,
      [
        'Authorization: Bearer ' . $token,
      ]
    );

    if ($code2 === 202) {
      $this->log("Deleted digest $short_sha $repo:$tags");
      return TRUE;
    }
    else {
      $this->log("Error, response code $code2 trying to delete digest \"$digest\" $repo:$tags\n$response2");
      return FALSE;
    }
  }

  /**
   * Determines if a tag is current and not past its expiry date.
   *
   * @param array $tag
   *   One tag record.
   *
   * @return bool
   *   Returns FALSE if tag is expired and should be deleted.
   *   Returns TRUE if tag should be retained. For safety, if the
   *   tag matches no patterns, then this will always return TRUE.
   */
  protected function isTagCurrent(array $tag): bool {
    $tag_name = $tag['name'];

    // Get the age of the tag. If none, mark as keep,
    // but this should not happen.
    $lastUpdated = $tag['last_updated'] ?? NULL;
    if (is_null($lastUpdated)) {
      $this->log("Warning, no last updated date available for tag \"$tag_name\"");
      return TRUE;
    }
    $lastUpdated = strtotime($lastUpdated);
    $tagAgeInDays = (time() - $lastUpdated) / 86400;

    $retention = NULL;
    foreach ($this->protectionRules as $pattern_regex => $pattern_retention) {
      // A NULL here indicates protection forever, change to a big number.
      $pattern_retention ??= PHP_INT_MAX;
      if (preg_match('/' . $pattern_regex . '/', $tag_name)) {
        if (is_null($retention) || $pattern_retention > $retention) {
          $retention = $pattern_retention;
        }
      }
    }

    return (is_null($retention) || $tagAgeInDays <= $retention);
  }

  /**
   * Deletes images in a repository past their expiration date.
   *
   * @param string $repository
   *   Repository in the format 'namespace/name'.
   *
   * @return void
   *   No return value.
   */
  public function cleanupRepository(string $repository): void {
    $this->log("Processing repository \"$repository\"");

    // Validate the repository specification.
    $parts = explode('/', $repository);
    if (count($parts) != 2) {
      $this->log("Error, invalid repository specification: \"$repository\", it must be of the format \"namespace/name\"");
      return;
    }
    [$namespace, $repo] = $parts;

    // Download all tags from the repository.
    $digests = $this->getDigestTags($namespace, $repo);

    $deleted = 0;
    $kept = 0;

    foreach ($digests as $digest_id => $tags) {
      $short_sha = substr($digest_id, 7, 7);

      // Evaluate all tags on this digest for retention rules.
      $retainDigest = FALSE;
      $tagNames = [];
      foreach ($tags as $tag) {
        $tag_name = $tag['name'];
        $tagNames[] = $tag_name;
        if ($this->isTagCurrent($tag)) {
          $retainDigest = TRUE;
        }
      }

      $implodedTagNames = implode(', ', $tagNames);
      if ($retainDigest) {
        $kept++;
        $this->log('Keeping digest ' . $short_sha . ' with tags ' . $implodedTagNames);
      }
      else {
        if ($this->deleteDigest($namespace, $repo, $digest_id, $implodedTagNames)) {
          $deleted++;
        }
      }
    }

    $this->log("Summary for repository \"$repo\":");
    $dryRunMessage = '';
    if ($this->dryRun) {
      $dryRunMessage = ' (This was a dry run. No digests were actually deleted)';
    }
    $this->log('  Digests deleted: ' . $deleted . $dryRunMessage);
    $this->log('  Digests kept: ' . $kept);
    $this->log('  Total digests: ' . count($digests));

    if ($this->emitMarkup) {
      $this->summary .= "\n### Results for \"$repo\"\n";
      $this->summary .= '- **Digests deleted**: ' . $deleted . $dryRunMessage . "\n";
      $this->summary .= '- **Digests kept**: ' . $kept . "\n";
      $this->summary .= '- **Total digests**: ' . count($digests) . "\n";
    }
  }

}

/**
 * Command Line Interface.
 *
 * For security, username and password are only passed in
 * through environment variables.
 */

$username = getenv('DOCKERHUB_USERNAME');
$password = getenv('DOCKERHUB_PASSWORD');
$options = getopt('', [
  'repositories:',
  'rules:',
  'summary-markup',
  'dry-run',
]);

// Required environment variable and parameter validation.
$validated = TRUE;
if (!$username) {
  fwrite(STDERR, "Error, missing DOCKERHUB_USERNAME environment variable\n");
  $validated = FALSE;
}
if (!$password) {
  fwrite(STDERR, "Error, missing DOCKERHUB_PASSWORD environment variable\n");
  $validated = FALSE;
}
if (!($options['repositories'] ?? NULL)) {
  fwrite(STDERR, "Error, missing --repositories parameter\n");
  $validated = FALSE;
}
if (!($options['rules'] ?? NULL)) {
  fwrite(STDERR, "Error, missing --rules parameter\n");
  $validated = FALSE;
}
if (!$validated) {
  exit(1);
}

$cleaner = new DockerhubExpire(
  $username,
  $password,
  $options['rules'],
  isset($options['summary-markup']),
  isset($options['dry-run']),
);

$repositories = preg_split('/,\s*/', $options['repositories']);

foreach ($repositories as $repository) {
  $cleaner->cleanupRepository($repository);
}

if (isset($options['summary-markup'])) {
  print $cleaner->summary;
}
