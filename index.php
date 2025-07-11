<?php

session_start();

$proxy_password = 'Admin'; // Change this to your password

if (!isset($_SESSION['authenticated'])) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password'])) {
        if ($_POST['password'] === $proxy_password) {
            $_SESSION['authenticated'] = true;
            header('Location: ' . $_SERVER['PHP_SELF']);
            exit;
        } else {
            $error = 'Wrong password!';
        }
    }

    echo '<!DOCTYPE html><html><head><title>Login</title></head><body>';
    if (!empty($error)) echo '<p style="color:red;">' . htmlspecialchars($error) . '</p>';
    echo '<form method="post">
            <input type="password" name="password" placeholder="Enter Password" required />
            <button type="submit">Login</button>
          </form>';
    echo '</body></html>';
    exit;
}

define('PROXY_START', microtime(true));

require("vendor/autoload.php");

use Proxy\Config;
use Proxy\Http\Request;
use Proxy\Proxy;

if (!function_exists('curl_version')) {
    die("cURL extension is not loaded!");
}

// load config...
Config::load('./config.php');

// custom config file to be written to by a bash script or something
Config::load('./custom_config.php');

if (!Config::get('app_key')) {
    die("app_key inside config.php cannot be empty!");
}

if (!Config::get('expose_php')) {
    header_remove('X-Powered-By');
}

// start the session
if (Config::get('session_enable')) {
    session_start();
}

// how are our URLs be generated from this point? this must be set here so the proxify_url function below can make use of it
if (Config::get('url_mode') == 2) {
    Config::set('encryption_key', md5(Config::get('app_key') . $_SERVER['REMOTE_ADDR']));
} elseif (Config::get('url_mode') == 3) {
    Config::set('encryption_key', md5(Config::get('app_key') . session_id()));
}

if (Config::get('session_enable')) {
    // very important!!! otherwise requests are queued while waiting for session file to be unlocked
    session_write_close();
}

// form submit in progress...
if (isset($_POST['url'])) {

    $url = $_POST['url'];
    $url = add_http($url);

    header("HTTP/1.1 302 Found");
    header('Location: ' . proxify_url($url));
    exit;

} elseif (!isset($_GET['q'])) {

    // must be at homepage - should we redirect somewhere else?
    if (Config::get('index_redirect')) {

        // redirect to...
        header("HTTP/1.1 302 Found");
        header("Location: " . Config::get('index_redirect'));

    } else {
        echo render_template("./templates/main.php", array('version' => Proxy::VERSION));
    }

    exit;
}

// decode q parameter to get the real URL
$url = url_decrypt($_GET['q']);

$proxy = new Proxy();

// load plugins
foreach (Config::get('plugins', array()) as $plugin) {

    $plugin_class = $plugin . 'Plugin';

    if (file_exists('./plugins/' . $plugin_class . '.php')) {

        // use user plugin from /plugins/
        require_once('./plugins/' . $plugin_class . '.php');

    } elseif (class_exists('\\Proxy\\Plugin\\' . $plugin_class)) {

        // does the native plugin from php-proxy package with such name exist?
        $plugin_class = '\\Proxy\\Plugin\\' . $plugin_class;
    }

    // otherwise plugin_class better be loaded already through composer.json and match namespace exactly \\Vendor\\Plugin\\SuperPlugin
    // $proxy->getEventDispatcher()->addSubscriber(new $plugin_class());

    $proxy->addSubscriber(new $plugin_class());
}

try {

    // request sent to index.php
    $request = Request::createFromGlobals();

    // remove all GET parameters such as ?q=
    $request->get->clear();

    if (isset($_SESSION['authenticated']) && isset($_GET['q'])) {
    $log_file = __DIR__ . '/logs/history.txt';

    $log_data = [
        'time' => date('Y-m-d H:i:s'),
        'ip' => $_SERVER['REMOTE_ADDR'],
        'url' => $_GET['q']
    ];

    $entry = json_encode($log_data) . PHP_EOL;

    if (!is_dir(__DIR__ . '/logs')) {
        mkdir(__DIR__ . '/logs', 0755, true);
    }

    file_put_contents($log_file, $entry, FILE_APPEND | LOCK_EX);
}

    // forward it to some other URL
    $response = $proxy->forward($request, $url);

    // if that was a streaming response, then everything was already sent and script will be killed before it even reaches this line
    $response->send();

} catch (Exception $ex) {

    // if the site is on server2.proxy.com then you may wish to redirect it back to proxy.com
    if (Config::get("error_redirect")) {

        $url = render_string(Config::get("error_redirect"), array(
            'error_msg' => rawurlencode($ex->getMessage())
        ));

        // Cannot modify header information - headers already sent
        header("HTTP/1.1 302 Found");
        header("Location: {$url}");

    } else {

        echo render_template("./templates/main.php", array(
            'url' => $url,
            'error_msg' => $ex->getMessage(),
            'version' => Proxy::VERSION
        ));

    }
}
