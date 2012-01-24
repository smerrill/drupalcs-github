<?php

# PSR-0 autoloading!
require 'vendor/.composer/autoload.php';

use Guzzle\Http\Client;

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
  $config_values['standard'] = 'DrupalCodingStandard';
  $config_values['reports'] = array('xml' => null);

  // Use output buffering to grab the XML output.
  ob_start();
  $numErrors = $phpcs->process($config_values);
  $xml_output = ob_get_contents();
  ob_end_clean();

  return $xml_output;
}

# @TODO: Get these values from a GitHub hook.
$user = 'smerrill';
$repo = 'cod_api';
$pull_request_id = 1;

$github = new Client("https://github.com/");
$github_api = new Client("https://api.github.com/");

$pull_request_response = $github_api->get("repos/${user}/${repo}/pulls/${pull_request_id}")->send();

if ($pull_request_response->getStatusCode() != 200) {
  die("Error retrieving JSON.");
}

$pull_request_info = json_decode($pull_request_response->getBody());

# Pseudo-code follows.

# Retrieve and parse the diff to get a list of files to be checked.

# Get the SHA of the head commit of the pull request and update a local git repo.
#   (As a possible fallback, pull a bunch of files from raw.github.com? Would that work for private repos?)

# Iterate over the list of files. For each file:
#   1. Parse the diff markers to know which diff positions are visible and can have comments posted to them.
#   2. Download the files and run them through run_php_codesniffer().
#   3. Use SimpleXML to iterate over the CodeSniffer XML and grab all errors that are within the visible diff positions.
#   4. Post those as comments through the GitHub Pull Request Comments API.

