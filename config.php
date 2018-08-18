<?php

// set your time zone:
date_default_timezone_set('America/Sao_Paulo');

// set locale (see supported locales: https://github.com/fightbulc/moment.php#switch-locale)
$config['locale'] = 'pt_BR';


// enable in production:
error_reporting(0);

// enable while testing:
//ini_set('display_errors', 1);
//ini_set('display_startup_errors', 1);
//error_reporting(E_ALL);


// Change IMAP settings (check SSL flags on http://php.net/manual/en/function.imap-open.php)
$config['imap']['url'] = '{imap.gmail.com:993/imap/ssl}INBOX';
$config['imap']['username'] = getenv("IMAP_USER");
$config['imap']['password'] = getenv("IMAP_PASSWORD");

// Mails to those usernames can not be accessed:
$config['blocked_usernames'] = array('root', 'admin', 'administrator', 'hostmaster', 'postmaster', 'webmaster');

// Mails are usually show as Text and only if not available as HTML. You can turn this around to prefer HTML over text.
$config['prefer_plaintext'] = true;

