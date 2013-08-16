<?php
/**
 * PHP Console
 *
 * A web-based php debug console
 *
 * Copyright (C) 2010, Jordi Boggiano
 * http://seld.be/ - j.boggiano@seld.be
 *
 * Licensed under the new BSD License
 * See the LICENSE file for details
 *
 * Source on Github http://github.com/Seldaek/php-console
 */

if (!in_array($_SERVER['REMOTE_ADDR'], array('127.0.0.1', '::1'), true)) {
    header('HTTP/1.1 401 Access unauthorized');
    die('ERR/401 Go Away');
}

ini_set('log_errors', 0);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL | E_STRICT);

// Parse the contents of the default.config.json file as an associative array.
$config = json_decode(file_get_contents('default.config.json'), TRUE);
if (!$config) {
  print 'Config error in default.config.json';
}

if (is_readable('my.config.json')) {
  // Append values of custom configuration to default values
  $customConfig = json_decode(file_get_contents('my.config.json'), TRUE);
  if ($customConfig) {
    $config = $customConfig ? $customConfig + $config : $config;
  }
  else if(file_get_contents('my.config.json')) {
    print 'Config error in my.config.json';
  }
}
$drupal_sites = !empty($config['drupal_sites']) ? $config['drupal_sites'] : array();
$options = !empty($config['options']) ? $config['options'] : array();

define('PHP_CONSOLE_VERSION', '1.3.0-dev');
require 'krumo/class.krumo.php';

$debugOutput = '';

/**
 * Bootstrap a drupal site
 */
if (isset($_POST['site'])) {
  if (in_array($_POST['site'], array_keys($config['drupal_sites']))) {
    setcookie('current_site', $_POST['site']);
    exit('OK');
  }
  else {
    header('HTTP/1.1 500 Internal Server Error');
    exit('Requested site not defined in the config');
  }
}

$current_site = !empty($_COOKIE['current_site']) ? $_COOKIE['current_site'] : NULL;

if (!$current_site && !empty($drupal_sites)){
  $current_site = key($drupal_sites);
}

if(!empty($current_site)) {
  // We must be actually in the root directory of Drupal installation.
  chdir($current_site);
  define('DONSOLE', TRUE);

  define('DRUPAL_ROOT', getcwd());
  require_once DRUPAL_ROOT . '/includes/bootstrap.inc';
  try {
    // We don't want the cached pages fired back at us.
    $GLOBALS['conf']['cache'] = FALSE;
    // Multi site set up?
    $matches = array();
    // Check for a portion of the config site alias in square brackets (site
    // url).
    if ( preg_match("/.*\[(.*)\].*/", $drupal_sites[$current_site], $matches) ) {
      if (!empty($matches[1]) && is_string($matches[1])) {
          // Set the server host variable to the url.
          $_SERVER['HTTP_HOST'] = $matches[1];
      }
    }
    drupal_bootstrap(DRUPAL_BOOTSTRAP_FULL);
    // let's be user 1
    $GLOBALS['user'] = user_load(1);

    // Remember which site we are using.
    setcookie('current_site', $current_site);
  }
  catch(Exception $e) {
    print $e->getMessage();
    setcookie('current_site', '');
  }
  
  if(!file_exists($current_site)){
    print 'Requested site cannot be found at ' . $current_site;
  }
  
}

$history = isset($_COOKIE['history']) ? unserialize($_COOKIE['history']) : array();

if (isset($_GET['h'])){
  $h = (int) $_GET['h'];
  if(empty($history[$h])) {
    header('HTTP/1.1 500 Internal Server Error');
    exit('History state not found');
  }

  exit("<?php\n\n" . $history[$h]);
}
if (isset($_POST['code'])) {
    if (get_magic_quotes_gpc()) {
        $code = stripslashes($code);
    }
    
    $code = trim(preg_replace('{^\s*<\?(php)?}i', '', $_POST['code']));

    if ( $code ) {
      $t = (int) microtime(true) * 1000000;
      $history[$t] = $code;
      $history = array_unique($history);
      $history = array_reverse($history, TRUE);
      $history = array_slice($history, 0, 10, TRUE);
      setcookie('history', serialize($history));
    }

    // if there's only one line wrap it into a krumo() call
    if (preg_match('#^(?!var_dump|echo|print|< )([^\r\n]+?);?\s*$#is', $code, $m) && trim($m[1])) {
        $code = 'krumo('.$m[1].');';
    }

    // replace '< foo' by krumo(foo)
    $code = preg_replace('#^<\s+(.+?);?[\r\n]?$#m', 'krumo($1);', $code);

    // replace newlines in the entire code block by the new specified one
    // i.e. put #\r\n on the first line to emulate a file with windows line
    // endings if you're on a unix box
    if (preg_match('{#((?:\\\\[rn]){1,2})}', $code, $m)) {
        $newLineBreak = str_replace(array('\\n', '\\r'), array("\n", "\r"), $m[1]);
        $code = preg_replace('#(\r?\n|\r\n?)#', $newLineBreak, $code);
    }

    ob_start();
    try {
      eval($code);
    }
    catch(Exception $e) {
      print $e->getMessage();
    }
    $debugOutput = ob_get_clean();

    if (isset($_GET['js'])) {
        header('Content-Type: text/plain');
        echo $debugOutput;
        session_write_close();
        die('#end-php-console-output#');
    }
}

?>
<!DOCTYPE html>
<html>
    <head>
        <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
        <meta http-equiv="X-UA-Compatible" content="IE=edge" />
        <title>DBG - <?php print empty($drupal_sites[$current_site]) ? '' : $drupal_sites[$current_site] . ' - '; ?>Debug Console</title>
        <link rel="stylesheet" type="text/css" href="styles.css" />
        <script src="jquery-1.9.1.min.js"></script>
        <script src="ace/ace.js" charset="utf-8"></script>
        <script src="ace/mode-php.js" charset="utf-8"></script>
        <script src="php-console.js"></script>
        <script>
            $.console({
                tabsize: <?php echo json_encode($options['tabsize']) ?>
            });
        </script>
        <link href="favicon.ico" rel="icon" type="image/x-icon" />
    </head>
    <body>
        
        <div class="output"><?php echo $debugOutput ?></div>
        <form method="POST" action="">
            <div class="input">
                <textarea class="editor" id="editor" name="code"><?php echo (isset($_POST['code']) ? htmlentities($_POST['code'], ENT_QUOTES, 'UTF-8') : "&lt;?php\n\n") ?></textarea>
                <div class="statusbar">
                    <span class="position">Line: 1, Column: 1</span>
                    <span class="copy">
                        Copy selection: <object classid="clsid:d27cdb6e-ae6d-11cf-96b8-444553540000" width="110" height="14" id="clippy">
                            <param name="movie" value="clippy/clippy.swf"/>
                            <param name="allowScriptAccess" value="always" />
                            <param name="quality" value="high" />
                            <param name="scale" value="noscale" />
                            <param NAME="FlashVars" value="text=">
                            <param name="bgcolor" value="#E8E8E8">
                            <embed src="clippy/clippy.swf"
                                   width="110"
                                   height="14"
                                   name="clippy"
                                   quality="high"
                                   allowScriptAccess="always"
                                   type="application/x-shockwave-flash"
                                   pluginspage="http://www.macromedia.com/go/getflashplayer"
                                   FlashVars="text="
                                   bgcolor="#E8E8E8"
                            />
                        </object>
                    </span>
                </div>
            </div>
            <input type="submit" name="subm" value="Try this!" />
        </form>
        <div class="help">
        debug:
            &lt; foo()
            krumo(foo());
        </div>
        <div class="help">
        misc:
            press ctrl-enter to submit
            put '#\n' on the first line to enforce
                \n line breaks (\r\n etc work too)
        commands:
            krumo::backtrace();
            krumo::includes();
            krumo::functions();
            krumo::classes();
            krumo::defines();
        </div>
        <div class="help">
          <div id="site-chooser">
            <label for="site-choice"></label>
            <select name="site-choice" id="site-choice">
              <?php foreach ( $drupal_sites as $dir => $site ) : ?>
              <?php $site_selected_option = $dir === $current_site ? ' selected="selected"' : ''; ?>
              <option<?php print $site_selected_option; ?>
                value="<?php print $dir; ?>"><?php print $site; ?></option>
              <?php endforeach; ?>
            </select>
            <div id="history">
            <?php foreach ( $history as $k => $h_code ) : ?>
              <a href="?js=1&h=<?php print $k; ?>"><?php print $k+1; ?> <?php print trim(substr($h_code, 0, 25)); ?>...</a>
            <?php endforeach; ?>
            </div>
          </div>

        </div>
        <div class="footer">
            php-console v<?php echo PHP_CONSOLE_VERSION ?> - by <a href="http://seld.be/">Jordi Boggiano</a> - <a href="http://github.com/Seldaek/php-console">sources on github</a>
        </div>
    </body>
</html>
