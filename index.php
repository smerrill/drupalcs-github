<?php
// PSR-0 autoloading!
require 'vendor/.composer/autoload.php';

use Guzzle\Http\Client;

// @TODO: Remove this.
header('Content-Type: text/plain');

/**
 * Runs PHP_Codesniffer as though you had done the following on the command line:
 * phpcs --standard=DrupalCodingStandard --extensions=php,module,inc,install,test,profile,theme --report=xml $file_path
 *
 */
function run_php_codesniffer($file_path) {
  if (is_file(dirname(__FILE__).'/../CodeSniffer/CLI.php') === true) {
    include_once dirname(__FILE__).'/../CodeSniffer/CLI.php';
  } else {
    include_once 'PHP/CodeSniffer/CLI.php';
  }

  $phpcs = new PHP_CodeSniffer_CLI();
  $phpcs->checkRequirements();

  # Fake some command-line values.
  $_SERVER['argc'] = 0;
  $_SERVER['argv'] = array();

  # Set up our own set of values by hand.
  $config_values = $phpcs->getDefaults();
  $config_values['extensions'] = array('php', 'module', 'inc', 'install', 'test', 'profile', 'theme');
  $config_values['files'] = array($file_path);
  $config_values['standard'] = 'Drupal';
  $config_values['reports'] = array('xml' => null);

  // Use output buffering to grab the XML output.
  ob_start();
  $numErrors = $phpcs->process($config_values);
  $xml_output = ob_get_contents();
  ob_end_clean();

  return $xml_output;
}

// @TODO: Get these values from a GitHub hook.
$user = 'smerrill';
$repo = 'cod_api';
$pull_request_id = 1;

$github = new Client("https://github.com/");
$github_api = new Client("https://api.github.com/");

// @TODO: Re-enable when done to stop using the GitHub API so much.
/*
$pull_request_response = $github_api->get("repos/${user}/${repo}/pulls/${pull_request_id}")->send();

if ($pull_request_response->getStatusCode() != 200) {
  die("Error retrieving JSON.");
}

$pull_request_info = json_decode($pull_request_response->getBody());
*/

$pull_request_info = json_decode(file_get_contents("test.json"));

$repo_ref = $pull_request_info->head->ref;
$repo_name = $pull_request_info->head->repo->name;
$repo_remote = $pull_request_info->head->repo->ssh_url;
$matches = array();
if (preg_match('/:(\w+)\/(\w+)\.git/', $repo_remote, $matches)) {
  $repo_remote_name = "${matches[1]}-${matches[2]}";
}
else {
  die("Could not parse a name for the remote repo.");
}

// Create the git repo if it has not been created yet.
$repo_path = dirname(__FILE__) . "/repos/${repo_name}";
if (!is_dir($repo_path)) {
  mkdir($repo_path);
}
if (!is_dir("${repo_path}/.git")) {
  shell_exec("git init ${repo_path}");
}

// Add the remote if it has not been created yet.
if (!preg_match("/${repo_remote_name}/", shell_exec("cd ${repo_path}; git remote show"))) {
  shell_exec("cd ${repo_path}; git remote add ${repo_remote_name} ${repo_remote}");
}

// @TODO: Add error checking!
$output = shell_exec("cd ${repo_path}; git fetch ${repo_remote_name}; git checkout ${repo_ref}");
if ($output == NULL) {
  // @TODO: Remove this hack.
  // die("Could not check out the ${repo_remote_name}/${repo_ref} branch.");
}

// Retrieve and parse the diff to get a list of files to be checked.
$diff_request_response = $github->get($pull_request_info->diff_url)->send();

if ($diff_request_response->getStatusCode() != 200) {
  die("Error retrieving diff.");
}

$diff_content = $diff_request_response->getBody();

// Clone the git repo!

$filenames_and_ranges = array();

$diff_file_chunks = preg_split("/diff --git /", $diff_content);
foreach ($diff_file_chunks as $file_diff) {
  // Filter out empty diff chunks.
  if (empty($file_diff)) {
    continue;
  }

  // Split up diffs and get ready to calculate its ranges.
  $file_diff_chunks = preg_split("/\n@@ [-0-9,]+ \+/", $file_diff);

  // Parse #1 for filename.
  $matches = array();
  preg_match("/a\/([^\s]+)/", $file_diff_chunks[0], $matches);
  if (!$filename = $matches[1]) {
    die("Error finding a filename for a diff segment.");
  }

  // Parse the rest for diff ranges.
  $diff_ranges = array();
  $diff_position_map = array();

  $diff_position = -1;
  foreach ($file_diff_chunks as $index => $chunk) {
    if ($index == 0) {
      continue;
    }

    $matches = array();
    preg_match("/(\d+),(\d+) @@/", $chunk, $matches);
    $range_start = $matches[1];
    $range_end = $matches[1] + $matches[2] - 1;
    $diff_ranges[] = array($range_start, $range_end);

    $diff_line = $range_start - 1;
    // Create a map of line in file -> position in diff.
    foreach (preg_split("/\n/", $chunk) as $index => $line) {
      if ($line == "") {
        continue;
      }

      $diff_position++;
      if ($index == 0) {
        continue;
      }
      if (preg_match("/^-/", $line)) {
        continue;
      }
      $diff_line++;

      $diff_position_map[$diff_line] = $diff_position;
    }
  }

  $filenames_and_ranges[$filename] = array(
    'ranges' => $diff_ranges,
    'positions' => $diff_position_map,
  );
}

$github_comments = array();

foreach ($filenames_and_ranges as $filename => $data) {
  $github_comments[$filename] = array();

  $xml_output = run_php_codesniffer("${repo_path}/${filename}");
  $xml_document = simplexml_load_string($xml_output);

  foreach ($xml_document->file->error as $error) {
    $error_line = (int) $error['line'];

    if (error_in_range($error_line, $data)) {
      // Convert the line to a diff position and add to the filename array.
      $github_comments[$filename][] = array(
        $data['positions'][$error_line] => (string) $error,
      );
    }
  }
}

die(print_r($github_comments, TRUE));

function error_in_range($line, $data) {
  return in_array($line, array_keys($data['positions']));
}

// Pseudo-code follows.

// Get the SHA of the head commit of the pull request and update a local git repo.
//   (As a possible fallback, pull a bunch of files from raw.github.com? Would that work for private repos?)

// Iterate over the list of files. For each file:
//   2. Download the files and run them through run_php_codesniffer().
//   3. Use SimpleXML to iterate over the CodeSniffer XML and grab all errors that are within the visible diff positions.
//   4. Post those as comments through the GitHub Pull Request Comments API.

