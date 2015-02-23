<?php

// Settings.
// Array of regexps for selecting files/paths to process. Files/Paths matching
// any following regexps will be included in the list of files to be processed.
$files_to_include_regexps = array('/^.*(\.php|\.inc|\.module|\.theme|\.install|\.js)$/');
// Array of regexps for excluding files/paths. Files/Paths matching any
// following regexps will be excluded from the list of files to be processed.
// Has priority over the previous parameter.
$files_to_exclude_regexps = array(
  ':.*/.git/.*:',
  ':^.git/.*:',
  ':.*default\.inc.*:',
  ':.*jquery.*:',
  ':.*panelizer\.inc.*:',
  ':.*\.features\..*:',
  ':.*\.min\.js.*:',
  ':.*strongarm\.inc.*:',
  ':.*App\.js.*:',
  ':.*getid3.*:',
  ':.*phpseclib.*:',
  ':.*fpdf16.*:',
  ':.*rml_tracked_return_retailer/classes/proxy.*:',
);

// Go.
main($files_to_include_regexps, $files_to_exclude_regexps);

/**
 * Displays a help message on how to use this script.
 */
function display_help() {
  echo "Should be used as follows:\n";
  echo "php " . $_SERVER['argv'][0] . " <input file or directory to be processed recursively> [dry-run]\n";
  echo "If dry-run is specified, the result is outputted only and the file doesn't get modified.\n";
}

/**
 * Main function.
 *
 * @param array $files_to_include_regexps
 *   Regexps filtering accepted files.
 * @param array $files_to_exclude_regexps
 *   Regexps excluding files. Has priority over the previous parameter.
 */
function main($files_to_include_regexps, $files_to_exclude_regexps) {
  // Start timer.
  $start_time = microtime(true);
  
  // Get argument.
  $input_file = isset($_SERVER['argv'][1]) ? $_SERVER['argv'][1] : NULL;

  if ($input_file === NULL) {
    display_help();
    exit(1);
  }
  
  $dry_run = isset($_SERVER['argv'][2]) ? $_SERVER['argv'][2] : FALSE;
  $dry_run = (bool) $dry_run;
  
  // Paths of files to process.
  $paths = array();

  if (is_file($input_file)) {
    $paths[] = $input_file;
  }
  elseif (is_dir($input_file)) {
    echo "Browsing files...\n";
    
    // Browse recursively the dir to process all files.
    $iter = new RecursiveIteratorIterator(
      new RecursiveDirectoryIterator($input_file, RecursiveDirectoryIterator::SKIP_DOTS),
      RecursiveIteratorIterator::LEAVES_ONLY,
      // Ignore "Permission denied".
      RecursiveIteratorIterator::CATCH_GET_CHILD
    );

    // Retrieve files.
    foreach ($iter as $path => $item) {
      if (!$item->isDir() && _should_file_be_processed($path, $files_to_include_regexps, $files_to_exclude_regexps)) {
        $paths[] = $path;
      }
    }
  }
  else {
    echo "File or directory not found.\n";
    exit(2);
  }

  // Process files.
  $number_files = count($paths);
  echo "$number_files files to process. In progress...\n";
  echo "\n";
  foreach ($paths as $i => $file_path) {
    // Process current file.
    process_file($file_path, $dry_run);
    
    // Display progress.
    $percentage = round((($i + 1) / $number_files) * 100, 2);
    echo ($i + 1) . "/$number_files files processed ($percentage%).\n";
  }
  
  // Summary end message.
  $end_time = microtime(true);
  $number_seconds = round($end_time - $start_time, 2);
  $number_minutes = round($number_seconds / 60, 2);
  echo "\n";
  echo "$number_files files processed in $number_seconds seconds ($number_minutes minutes).\n";
}

/**
 * Helper checking whether a file should be processed or not.
 *
 * Does that based on given configuration arrays of regexps.
 *
 * @param array $files_to_include_regexps
 *   Regexps filtering accepted files.
 * @param array $files_to_exclude_regexps
 *   Regexps excluding files. Has priority over the previous parameter.
 *
 * @return bool
 *   TRUE if the file should be processed. FALSE otherwise.
 */
function _should_file_be_processed($file_path, $files_to_include_regexps, $files_to_exclude_regexps) {
  // Loop over inclusion regexps until a matching one is found.
  $file_should_be_included = FALSE;
  foreach ($files_to_include_regexps as $regexp) {
    if (preg_match($regexp, $file_path)) {
      // Current path matched one of the inclusion regexp. Mark the file as
      // potentially good to process.
      $file_should_be_included = TRUE;
      break;
    }
  }
  
  // If we did not flag the file as potentially good to process so far, it
  // means we don't want it. Return FALSE.
  if (!$file_should_be_included) {
    return FALSE;
  }
  
  // Loop over exclusion regexps until a matching one is found.
  foreach ($files_to_exclude_regexps as $regexp) {
    if (preg_match($regexp, $file_path)) {
      // Current path matched one of the exclusion regexp. Return FALSE.
      return FALSE;
    }
  }
  
  // If we reach this line, it means the file matched one of the inclusion
  // regexps, and none of the exclusion ones. File is good to go. Return TRUE.
  return TRUE;
}

/**
 * Apply filters and fixes to given file.
 *
 * @param string $file_path
 *   Path to the file to process.
 * @param bool $dry_run
 *   If TRUE, the result will be outputted instead of being saved to disk.
 */
function process_file($file_path, $dry_run) {
  // Load input file.
  $file_content = file_get_contents($file_path);

  // List of functions to apply to the file content.
  $fixer_functions = array(
    'fix_line_breaks',
    'fix_end_of_line_spaces',
    'fix_final_line_breaks',
    'fix_dollariddollar_comments',
    'fix_inline_comments',
  );

  // Apply all functions.
  foreach ($fixer_functions as $function) {
    $function($file_content);
  }

  if ($dry_run) {
    // If it's a dry run, just output the result.
    echo "\n";
    $result_headline = "File: $file_path";
    echo "$result_headline\n";
    echo str_repeat('-', mb_strlen($result_headline, 'utf-8')) . "\n";
    print $file_content;
    echo "\n";
  }
  else {
    // If it's not, apply changes to the file.
    file_put_contents($file_path, $file_content);
  }
}

/**
 * Makes sure line breaks are LINUX ones (LF).
 *
 * @param string $in
 *   Input string to process. Will get modified as it's passed by reference.
 */
function fix_line_breaks(&$in) {
  $in = preg_replace(':(*BSR_ANYCRLF)\R:m', "\n", $in);
}

/**
 * Removes white spaces at the end of lines.
 *
 * @param string $in
 *   Input string to process. Will get modified as it's passed by reference.
 */
function fix_end_of_line_spaces(&$in) {
  // Remove extra white characters except new lines ([^\S\n]) from end of lines (that is
  // all white characters except new lines).
  $in = preg_replace(':^(.*?)[^\S\n]*$:m', '$1', $in);
}

/**
 * Makes sure the file only contains 1 line break at the end.
 *
 * @param string $in
 *   Input string to process. Will get modified as it's passed by reference.
 */
function fix_final_line_breaks(&$in) {
  // Remove all final line breaks.
  $in = preg_replace(':\n*\Z:m', '', $in);
  // Add one final line break.
  $in .= "\n";
}

/**
 * Removes special comments "// $Id$".
 *
 * @param string $in
 *   Input string to process. Will get modified as it's passed by reference.
 */
function fix_dollariddollar_comments(&$in) {
  // Remove '// $Id$...'.
  $in = preg_replace(':^[^\S\n]*//[^\S\n]*\$Id\$.*\n*:m', '', $in);
  
  // Remove '// $Id:...$'.
  $in = preg_replace('#^[^\S\n]*//[^\S\n]*\$Id:.*\$[^\S\n]*\n*#m', '', $in);
}

/**
 * Makes sure inline comments have the right format.
 *
 * @param string $in
 *   Input string to process. Will get modified as it's passed by reference.
 */
function fix_inline_comments(&$in) {
  // Split on line breaks.
  $lines = explode("\n", $in);
  
  // Browse the whole file and spot comments to rework them.
  $comment_regexp = _get_comment_regexp();
  $prev_line_was_a_comment = FALSE;
  $num_lines = count($lines);
  for ($i = 0; $i < $num_lines; $i++) {
    // Current line (by reference):
    $current_line = &$lines[$i];
    // Next line:
    if ($i < $num_lines - 1) {
      $next_line = $lines[$i + 1];
    }
    else {
      $next_line = '';
    }
    
    // Is current line a comment?
    $cur_line_is_a_com = _is_comment_to_be_processed($current_line);
    // Is next line a comment?
    $next_line_is_a_com = _is_comment_to_be_processed($next_line);

    // Current line is a comment.
    if ($cur_line_is_a_com) {
      // Add initial space to comment unless the comment does not contain text.
      $matches = array();
      if (preg_match($comment_regexp, $current_line, $matches) && !empty($matches[4])) {
        $current_line = preg_replace($comment_regexp, '$2 $4', $current_line);
      }
      
      // If previous line was not a comment, capitalize first letter.
      if (!$prev_line_was_a_comment) {
        $matches = array();
        if (preg_match($comment_regexp, $current_line, $matches)) {
          $comment_string = _capitalize_comment($matches[4]);
          $current_line = $matches[1] . $comment_string;
        }
      }
      
      // If next line is not a comment, make sure the last character is either
      // a "?", "!" or ".".
      // We also tolerate ';' and '}' as it's usually commented out code.
      // We skip comments starting with "@" though as they could be directives like
      // @codeCoverageIgnoreStart that we should not temper with.
      preg_match($comment_regexp, $current_line, $matches);
      $starts_with_at_sign = preg_match(':^[^\S\n]*@.*$:', $matches[4]);
      if (!$next_line_is_a_com && !$starts_with_at_sign) {
        $last_character = mb_substr($current_line, -1, NULL, 'utf-8');
        // If last char is a ':', just replace it with a '.'.
        if ($last_character == ':') {
          $current_line = mb_substr($current_line, 0, mb_strlen($current_line, 'utf-8') - 1, 'utf-8') . '.';
        }
        // Otherwise, if last char is different from '?', '!', '.' or ';', add a '.'.
        elseif (!in_array($last_character, array('?', '!', '.', ';', '}'))) {
          $current_line .= '.';
        }
      }
    }
    
    // Remember if last line was a comment or not for next loop.
    $prev_line_was_a_comment = $cur_line_is_a_com;
  }
  
  // Put all lines together again.
  $in = implode("\n", $lines);
}

/**
 * Helper returning the common regexp used to detect and parse a comment.
 *
 * @return string.
 *   Regexp used to detect a comment, and match its subparts:
 *   - $1 contains leading spaces, "//" and the space following slashes if any.
 *   - $2 contains leading spaces and "//".
 *   - $3 contains ' ' if a space follows slashes, or '' otherwise.
 *   - $4 contains the comment string. Could be empty.
 */
function _get_comment_regexp() {
  return ':^(([^\S\n]*//)( ?))(.*)$:';
}

/**
 * Helper detecting if given line is a comment and if it should be processed.
 *
 * @param string $line
 *   Line to test.
 *
 * @return bool
 *   TRUE if given line appears to be a valid comment that should be processed.
 *   FALSE otherwise.
 */
function _is_comment_to_be_processed($line) {
  $comment_regexp = _get_comment_regexp();
  
  // If doesn't match comment format, do not process.
  if (!preg_match($comment_regexp, $line)) {
    return FALSE;
  }
  
  // If looks like a HTML comment, do not process.
  if (preg_match(':^[^\S\n]*//[^\S\n]*-->.*$:', $line)) {
    return FALSE;
  }
  
  // If it looks like an old-fashioned JS escaper, do not process.
  // I.e.: //<![CDATA[ or //]]>.
  if (preg_match(':^[^\S\n]*//[^\S\n]*<!\[CDATA\[.*$:', $line)) {
    return FALSE;
  }
  if (preg_match(':^[^\S\n]*//[^\S\n]*\]\]>.*$:', $line)) {
    return FALSE;
  }
  
  // Other cases should be fine to process.
  return TRUE;
}

/**
 * Helper capitalizing the first letter of a comment, if appropriate.
 *
 * @param string $comment_string
 *   Comment string only (excluding "// ").
 *
 * @return string
 *   Capitalized string, or the same string.
 */
function _capitalize_comment($comment_string) {
  $words = explode(' ', $comment_string);
  $first_word = $words[0];
  
  // If first word contains '$', '(' or ')', ignore capitalization.
  $exception_elements = array('_', '$', '(' or ')');
  foreach ($exception_elements as $exception) {
    if (mb_strpos($first_word, $exception, 0, 'utf-8') !== FALSE) {
      return $comment_string;
    }
  }
  
  // Also ignore capitalization if the first word looks like a file name.
  // The word will be considered as a file name if it contains at least a dot,
  // and if it's not the last character.
  $position_of_a_dot = mb_strpos($first_word, '.', 0, 'utf-8');
  if ($position_of_a_dot !== FALSE && $position_of_a_dot < mb_strlen($first_word, 'utf-8') - 1) {
    return $comment_string;
  }
  
  // Capitalize.
  $capitalized_comment = mb_strtoupper(mb_substr($comment_string, 0, 1, 'utf-8'), 'utf-8');
  $capitalized_comment .= mb_substr($comment_string, 1, NULL, 'utf-8');
  
  return $capitalized_comment;
}
