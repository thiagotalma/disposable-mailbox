<?php namespace PhpImap;

use stdClass;

/**
 * @see https://github.com/barbushin/php-imap
 * @author Barbushin Sergey http://linkedin.com/in/barbushin
 */
class Mailbox {

	protected $imapPath;
	protected $imapLogin;
	protected $imapPassword;
	protected $connectionRetry = 0;
	protected $connectionRetryDelay = 100;
	protected $imapOptions = 0;
	protected $imapRetriesNum = 0;
	protected $imapParams = array();
	protected $serverEncoding;
	protected $attachmentsDir = null;
	protected $expungeOnDisconnect = true;
	protected $timeouts = array();
	private $imapStream;

	/**
	 * @param string $imapPath
	 * @param string $login
	 * @param string $password
	 * @param string $attachmentsDir
	 * @param string $serverEncoding
	 * @throws Exception
	 */
	public function __construct($imapPath, $login, $password, $attachmentsDir = null, $serverEncoding = 'UTF-8') {
		$this->setImapPath($imapPath);
		$this->imapLogin = $login;
		$this->imapPassword = $password;
		$this->serverEncoding = strtoupper($serverEncoding);
		if($attachmentsDir) {
			if(!is_dir($attachmentsDir)) {
				throw new Exception('Directory "' . $attachmentsDir . '" not found');
			}
			$this->attachmentsDir = rtrim(realpath($attachmentsDir), '\\/');
		}
	}

	public function getServerEncoding() {
		return $this->serverEncoding;
	}

	public function setServerEncoding($serverEncoding) {
		$this->serverEncoding = $serverEncoding;
	}

	/**
	 * @param int $timeout Timeout in seconds
	 * @param array $types One of the following: IMAP_OPENTIMEOUT, IMAP_READTIMEOUT, IMAP_WRITETIMEOUT, IMAP_CLOSETIMEOUT
	 */
	public function setTimeouts($timeout, $types = array(IMAP_OPENTIMEOUT, IMAP_READTIMEOUT, IMAP_WRITETIMEOUT, IMAP_CLOSETIMEOUT)) {
		$this->timeouts = array_fill_keys($types, $timeout);
	}

	/**
	 * @return string
	 */
	public function getLogin() {
		return $this->imapLogin;
	}

	/**
	 * Set custom connection arguments of imap_open method. See http://php.net/imap_open
	 * @param int $options
	 * @param int $retriesNum
	 * @param array $params
	 */
	public function setConnectionArgs($options = 0, $retriesNum = 0, array $params = null) {
		$this->imapOptions = $options;
		$this->imapRetriesNum = $retriesNum;
		$this->imapParams = $params;
	}

	/**
	 * Set custom folder for attachments in case you want to have tree of folders for each email
	 * i.e. a/1 b/1 c/1 where a,b,c - senders, i.e. john@smith.com
	 * @param string $dir folder where to save attachments
	 *
	 * @return void
	 */
	public function setAttachmentsDir($dir) {
		$this->attachmentsDir = $dir;
	}

	public function setConnectionRetry($maxAttempts) {
		$this->connectionRetry = $maxAttempts;
	}

	public function setConnectionRetryDelay($milliseconds) {
		$this->connectionRetryDelay = $milliseconds;
	}

	/**
	 * Get IMAP mailbox connection stream
	 * @param bool $forceConnection Initialize connection if it's not initialized
	 * @return null|resource
	 */
	public function getImapStream($forceConnection = true) {
		if($forceConnection) {
			if($this->imapStream && (!is_resource($this->imapStream) || !imap_ping($this->imapStream))) {
				$this->disconnect();
				$this->imapStream = null;
			}
			if(!$this->imapStream) {
				$this->imapStream = $this->initImapStreamWithRetry();
			}
		}
		return $this->imapStream;
	}

	/**
	 * Switch mailbox without opening a new connection
	 *
	 * @param string $imapPath
	 * @throws Exception
	 */
	public function switchMailbox($imapPath = '') {
		$this->setImapPath($imapPath);
		$imapStream = @imap_reopen($this->getImapStream(), $this->imapPath);
		if(!$imapStream) {
			throw new Exception("Couldn't switch  mailbox: " . imap_last_error());
		}
	}

	protected function initImapStreamWithRetry() {
		$retry = $this->connectionRetry;

		do {
			try {
				return $this->initImapStream();
			}
			catch(ConnectionException $exception) {
			}
		}
		while(--$retry > 0 && (!$this->connectionRetryDelay || !usleep($this->connectionRetryDelay * 1000)));

		throw $exception;
	}

	protected function initImapStream() {
		foreach($this->timeouts as $type => $timeout) {
			imap_timeout($type, $timeout);
		}
		$imapStream = @imap_open($this->imapPath, $this->imapLogin, $this->imapPassword, $this->imapOptions, $this->imapRetriesNum, $this->imapParams);
		if(!$imapStream) {
			$lastError = imap_last_error();
			imap_errors();
			throw new ConnectionException('Connection error: ' . $lastError);
		}
		return $imapStream;
	}

	public function disconnect() {
		$imapStream = $this->getImapStream(false);
		if($imapStream && is_resource($imapStream)) {
			imap_close($imapStream, $this->expungeOnDisconnect ? CL_EXPUNGE : 0);
		}
	}

	/**
	 * Sets 'expunge on disconnect' parameter
	 * @param bool $isEnabled
	 */
	public function setExpungeOnDisconnect($isEnabled) {
		$this->expungeOnDisconnect = $isEnabled;
	}

	/**
	 * Get information about the current mailbox.
	 *
	 * Returns the information in an object with following properties:
	 *  Date - current system time formatted according to RFC2822
	 *  Driver - protocol used to access this mailbox: POP3, IMAP, NNTP
	 *  Mailbox - the mailbox name
	 *  Nmsgs - number of mails in the mailbox
	 *  Recent - number of recent mails in the mailbox
	 *
	 * @return stdClass
	 */
	public function checkMailbox() {
		return imap_check($this->getImapStream());
	}

	/**
	 * Creates a new mailbox
	 * @param $name
	 * @return bool
	 */
	public function createMailbox($name) {
		return imap_createmailbox($this->getImapStream(), imap_utf7_encode($this->imapPath . '.' . $name));
	}

	/**
	 * Delete mailbox
	 * @param $name
	 * @return bool
	 */
	public function deleteMailbox($name) {
		return imap_deletemailbox($this->getImapStream(), imap_utf7_encode($this->imapPath . '.' . $name));
	}

	/**
	 * Rename mailbox
	 * @param $oldName
	 * @param $newName
	 * @return bool
	 */
	public function renameMailbox($oldName, $newName) {
		return imap_renamemailbox($this->getImapStream(), imap_utf7_encode($this->imapPath . '.' . $oldName), imap_utf7_encode($this->imapPath . '.' . $newName));
	}



	/**
	 * Gets status information about the given mailbox.
	 *
	 * This function returns an object containing status information.
	 * The object has the following properties: messages, recent, unseen, uidnext, and uidvalidity.
	 *
	 * @return stdClass if the box doesn't exist
	 */
	public function statusMailbox() {
		return imap_status($this->getImapStream(), $this->imapPath, SA_ALL);
	}

	/**
	 * Gets listing the folders
	 *
	 * This function returns an object containing listing the folders.
	 * The object has the following properties: messages, recent, unseen, uidnext, and uidvalidity.
	 *
	 * @param string $pattern
	 * @return array listing the folders
	 * @throws Exception
	 */
	public function getListingFolders($pattern = '*') {
		$folders = imap_list($this->getImapStream(), $this->imapPath, $pattern);
		if($folders === false) {
			throw new Exception("Unexpected response from IMAP server");
		}
		foreach($folders as $key => $folder) {
			$folders[$key] = imap_utf7_decode($folder);
		}
		return $folders;
	}

	/**
	 * This function uses imap_search() to perform a search on the mailbox currently opened in the given IMAP stream.
	 * For example, to match all unanswered mails sent by Mom, you'd use: "UNANSWERED FROM mom".
	 *
	 * @param string $criteria See http://php.net/imap_search for a complete list of available criteria
	 * @return array mailsIds (or empty array)
	 */
	public function searchMailbox($criteria = 'ALL') {
		$mailsIds = imap_search($this->getImapStream(), $criteria, SE_UID, $this->serverEncoding);
		return $mailsIds ? $mailsIds : array();
	}

	/**
	 * Save mail body.
	 * @param $mailId
	 * @param string $filename
	 * @return bool
	 */
	public function saveMail($mailId, $filename = 'email.eml') {
		return imap_savebody($this->getImapStream(), $filename, $mailId, "", FT_UID);
	}

	/**
	 * Marks mails listed in mailId for deletion.
	 * @param $mailId
	 * @return bool
	 */
	public function deleteMail($mailId) {
		return imap_delete($this->getImapStream(), $mailId . ':' . $mailId, FT_UID);
	}

	/**
	 * Moves mails listed in mailId into new mailbox
	 * @param $mailId
	 * @param $mailBox
	 * @return bool
	 */
	public function moveMail($mailId, $mailBox) {
		return imap_mail_move($this->getImapStream(), $mailId, $mailBox, CP_UID) && $this->expungeDeletedMails();
	}

	/**
	 * Copys mails listed in mailId into new mailbox
	 * @param $mailId
	 * @param $mailBox
	 * @return bool
	 */
	public function copyMail($mailId, $mailBox) {
		return imap_mail_copy($this->getImapStream(), $mailId, $mailBox, CP_UID) && $this->expungeDeletedMails();
	}

	/**
	 * Deletes all the mails marked for deletion by imap_delete(), imap_mail_move(), or imap_setflag_full().
	 * @return bool
	 */
	public function expungeDeletedMails() {
		return imap_expunge($this->getImapStream());
	}

	/**
	 * Add the flag \Seen to a mail.
	 * @param $mailId
	 * @return bool
	 */
	public function markMailAsRead($mailId) {
		return $this->setFlag(array($mailId), '\\Seen');
	}

	/**
	 * Remove the flag \Seen from a mail.
	 * @param $mailId
	 * @return bool
	 */
	public function markMailAsUnread($mailId) {
		return $this->clearFlag(array($mailId), '\\Seen');
	}

	/**
	 * Add the flag \Flagged to a mail.
	 * @param $mailId
	 * @return bool
	 */
	public function markMailAsImportant($mailId) {
		return $this->setFlag(array($mailId), '\\Flagged');
	}

	/**
	 * Add the flag \Seen to a mails.
	 * @param array $mailId
	 * @return bool
	 */
	public function markMailsAsRead(array $mailId) {
		return $this->setFlag($mailId, '\\Seen');
	}

	/**
	 * Remove the flag \Seen from some mails.
	 * @param array $mailId
	 * @return bool
	 */
	public function markMailsAsUnread(array $mailId) {
		return $this->clearFlag($mailId, '\\Seen');
	}

	/**
	 * Add the flag \Flagged to some mails.
	 * @param array $mailId
	 * @return bool
	 */
	public function markMailsAsImportant(array $mailId) {
		return $this->setFlag($mailId, '\\Flagged');
	}

	/**
	 * Causes a store to add the specified flag to the flags set for the mails in the specified sequence.
	 *
	 * @param array $mailsIds
	 * @param string $flag which you can set are \Seen, \Answered, \Flagged, \Deleted, and \Draft as defined by RFC2060.
	 * @return bool
	 */
	public function setFlag(array $mailsIds, $flag) {
		return imap_setflag_full($this->getImapStream(), implode(',', $mailsIds), $flag, ST_UID);
	}

	/**
	 * Cause a store to delete the specified flag to the flags set for the mails in the specified sequence.
	 *
	 * @param array $mailsIds
	 * @param string $flag which you can set are \Seen, \Answered, \Flagged, \Deleted, and \Draft as defined by RFC2060.
	 * @return bool
	 */
	public function clearFlag(array $mailsIds, $flag) {
		return imap_clearflag_full($this->getImapStream(), implode(',', $mailsIds), $flag, ST_UID);
	}

	/**
	 * Fetch mail headers for listed mails ids
	 *
	 * Returns an array of objects describing one mail header each. The object will only define a property if it exists. The possible properties are:
	 *  subject - the mails subject
	 *  from - who sent it
	 *  to - recipient
	 *  date - when was it sent
	 *  message_id - Mail-ID
	 *  references - is a reference to this mail id
	 *  in_reply_to - is a reply to this mail id
	 *  size - size in bytes
	 *  uid - UID the mail has in the mailbox
	 *  msgno - mail sequence number in the mailbox
	 *  recent - this mail is flagged as recent
	 *  flagged - this mail is flagged
	 *  answered - this mail is flagged as answered
	 *  deleted - this mail is flagged for deletion
	 *  seen - this mail is flagged as already read
	 *  draft - this mail is flagged as being a draft
	 *
	 * @param array $mailsIds
	 * @return array
	 */
	public function getMailsInfo(array $mailsIds) {
		$mails = imap_fetch_overview($this->getImapStream(), implode(',', $mailsIds), FT_UID);
		if(is_array($mails) && count($mails)) {
			foreach($mails as &$mail) {
				if(isset($mail->subject)) {
					$mail->subject = $this->decodeMimeStr($mail->subject, $this->serverEncoding);
				}
				if(isset($mail->from)) {
					$mail->from = $this->decodeMimeStr($mail->from, $this->serverEncoding);
				}
				if(isset($mail->to)) {
					$mail->to = $this->decodeMimeStr($mail->to, $this->serverEncoding);
				}
			}
		}
		return $mails;
	}

	/**
	 * Get headers for all messages in the defined mailbox,
	 * returns an array of string formatted with header info,
	 * one element per mail message.
	 *
	 * @return array
	 */
	public function getMailboxHeaders() {
		return imap_headers($this->getImapStream());
	}

	/**
	 * Get information about the current mailbox.
	 *
	 * Returns an object with following properties:
	 *  Date - last change (current datetime)
	 *  Driver - driver
	 *  Mailbox - name of the mailbox
	 *  Nmsgs - number of messages
	 *  Recent - number of recent messages
	 *  Unread - number of unread messages
	 *  Deleted - number of deleted messages
	 *  Size - mailbox size
	 *
	 * @return object Object with info | FALSE on failure
	 */

	public function getMailboxInfo() {
		return imap_mailboxmsginfo($this->getImapStream());
	}

	/**
	 * Gets mails ids sorted by some criteria
	 *
	 * Criteria can be one (and only one) of the following constants:
	 *  SORTDATE - mail Date
	 *  SORTARRIVAL - arrival date (default)
	 *  SORTFROM - mailbox in first From address
	 *  SORTSUBJECT - mail subject
	 *  SORTTO - mailbox in first To address
	 *  SORTCC - mailbox in first cc address
	 *  SORTSIZE - size of mail in octets
	 *
	 * @param int $criteria
	 * @param bool $reverse
	 * @return array Mails ids
	 */
	public function sortMails($criteria = SORTARRIVAL, $reverse = true) {
		return imap_sort($this->getImapStream(), $criteria, $reverse, SE_UID);
	}

	/**
	 * Get mails count in mail box
	 * @return int
	 */
	public function countMails() {
		return imap_num_msg($this->getImapStream());
	}

	/**
	 * Retrieve the quota settings per user
	 * @return array - FALSE in the case of call failure
	 */
	protected function getQuota() {
		return imap_get_quotaroot($this->getImapStream(), 'INBOX');
	}

	/**
	 * Return quota limit in KB
	 * @return int - FALSE in the case of call failure
	 */
	public function getQuotaLimit() {
		$quota = $this->getQuota();
		return isset($quota['STORAGE']['limit']) ? $quota['STORAGE']['limit'] : false;
	}

	/**
	 * Return quota usage in KB
	 * @return int FALSE in the case of call failure
	 */
	public function getQuotaUsage() {
		$quota = $this->getQuota();
		return isset($quota['STORAGE']['usage']) ? $quota['STORAGE']['usage'] : false;
	}

	/**
	 * Get raw mail data
	 *
	 * @param $msgId
	 * @param bool $markAsSeen
	 * @return mixed
	 */
	public function getRawMail($msgId, $markAsSeen = true) {
		$options = FT_UID;
		if(!$markAsSeen) {
			$options |= FT_PEEK;
		}

		return imap_fetchbody($this->getImapStream(), $msgId, '', $options);
	}

	/**
	 * Get mail header
	 *
	 * @param $mailId
	 * @return IncomingMailHeader
	 */
	public function getMailHeader($mailId) {
		$headersRaw = imap_fetchheader($this->getImapStream(), $mailId, FT_UID);
		$head = imap_rfc822_parse_headers($headersRaw);

		$header = new IncomingMailHeader();
		$header->headersRaw = $headersRaw;
		$header->headers = $head;
		$header->id = $mailId;
		$header->date = date('Y-m-d H:i:s', isset($head->date) ? strtotime(preg_replace('/\(.*?\)/', '', $head->date)) : time());
		$header->subject = isset($head->subject) ? $this->decodeMimeStr($head->subject, $this->serverEncoding) : null;
		if(isset($head->from)) {
			$header->fromName = isset($head->from[0]->personal) ? $this->decodeMimeStr($head->from[0]->personal, $this->serverEncoding) : null;
			$header->fromAddress = strtolower($head->from[0]->mailbox . '@' . $head->from[0]->host);
		}
		elseif(preg_match("/smtp.mailfrom=[-0-9a-zA-Z.+_]+@[-0-9a-zA-Z.+_]+.[a-zA-Z]{2,4}/", $headersRaw, $matches)) {
			$header->fromAddress = substr($matches[0], 14);
		}
		if(isset($head->to)) {
			$toStrings = array();
			foreach($head->to as $to) {
				if(!empty($to->mailbox) && !empty($to->host)) {
					$toEmail = strtolower($to->mailbox . '@' . $to->host);
					$toName = isset($to->personal) ? $this->decodeMimeStr($to->personal, $this->serverEncoding) : null;
					$toStrings[] = $toName ? "$toName <$toEmail>" : $toEmail;
					$header->to[$toEmail] = $toName;
				}
			}
			$header->toString = implode(', ', $toStrings);
		}

		if(isset($head->cc)) {
			foreach($head->cc as $cc) {
				if(!empty($cc->mailbox) && !empty($cc->host)) {
					$header->cc[strtolower($cc->mailbox . '@' . $cc->host)] = isset($cc->personal) ? $this->decodeMimeStr($cc->personal, $this->serverEncoding) : null;
				}
			}
		}

		if(isset($head->bcc)) {
			foreach($head->bcc as $bcc) {
				if(!empty($bcc->mailbox) && !empty($bcc->host)) {
					$header->bcc[strtolower($bcc->mailbox . '@' . $bcc->host)] = isset($bcc->personal) ? $this->decodeMimeStr($bcc->personal, $this->serverEncoding) : null;
				}
			}
		}

		if(isset($head->reply_to)) {
			foreach($head->reply_to as $replyTo) {
				$header->replyTo[strtolower($replyTo->mailbox . '@' . $replyTo->host)] = isset($replyTo->personal) ? $this->decodeMimeStr($replyTo->personal, $this->serverEncoding) : null;
			}
		}

		if(isset($head->message_id)) {
			$header->messageId = $head->message_id;
		}

		return $header;
	}

	/**
	 * Get mail data
	 *
	 * @param $mailId
	 * @param bool $markAsSeen
	 * @return IncomingMail
	 */
	public function getMail($mailId, $markAsSeen = true) {
		$mail = new IncomingMail();
		$mail->setHeader($this->getMailHeader($mailId));

		$mailStructure = imap_fetchstructure($this->getImapStream(), $mailId, FT_UID);

		if(empty($mailStructure->parts)) {
			$this->initMailPart($mail, $mailStructure, 0, $markAsSeen);
		}
		else {
			foreach($mailStructure->parts as $partNum => $partStructure) {
				$this->initMailPart($mail, $partStructure, $partNum + 1, $markAsSeen);
			}
		}

		return $mail;
	}

	protected function initMailPart(IncomingMail $mail, $partStructure, $partNum, $markAsSeen = true) {
		$options = FT_UID;
		if(!$markAsSeen) {
			$options |= FT_PEEK;
		}

		if($partNum) { // don't use ternary operator to optimize memory usage / parsing speed (see http://fabien.potencier.org/the-php-ternary-operator-fast-or-not.html)
			$data = imap_fetchbody($this->getImapStream(), $mail->id, $partNum, $options);
		}
		else {
			$data = imap_body($this->getImapStream(), $mail->id, $options);
		}

		if($partStructure->encoding == 1) {
			$data = imap_utf8($data);
		}
		elseif($partStructure->encoding == 2) {
			$data = imap_binary($data);
		}
		elseif($partStructure->encoding == 3) {
			$data = preg_replace('~[^a-zA-Z0-9+=/]+~s', '', $data); // https://github.com/barbushin/php-imap/issues/88
			$data = imap_base64($data);
		}
		elseif($partStructure->encoding == 4) {
			$data = quoted_printable_decode($data);
		}

		$params = array();
		if(!empty($partStructure->parameters)) {
			foreach($partStructure->parameters as $param) {
				$params[strtolower($param->attribute)] = $this->decodeMimeStr($param->value);
			}
		}
		if(!empty($partStructure->dparameters)) {
			foreach($partStructure->dparameters as $param) {
				$paramName = strtolower(preg_match('~^(.*?)\*~', $param->attribute, $matches) ? $matches[1] : $param->attribute);
				if(isset($params[$paramName])) {
					$params[$paramName] .= $param->value;
				}
				else {
					$params[$paramName] = $param->value;
				}
			}
		}

		$isAttachment = $partStructure->ifid || isset($params['filename']) || isset($params['name']);

		// ignore contentId on body when mail isn't multipart (https://github.com/barbushin/php-imap/issues/71)
		if(!$partNum && TYPETEXT === $partStructure->type) {
			$isAttachment = false;
		}

		if($isAttachment) {
			$attachmentId = mt_rand() . mt_rand();

			if(empty($params['filename']) && empty($params['name'])) {
				$fileName = $attachmentId . '.' . strtolower($partStructure->subtype);
			}
			else {
				$fileName = !empty($params['filename']) ? $params['filename'] : $params['name'];
				$fileName = $this->decodeMimeStr($fileName, $this->serverEncoding);
				$fileName = $this->decodeRFC2231($fileName, $this->serverEncoding);
			}

			$attachment = new IncomingMailAttachment();
			$attachment->id = $attachmentId;
			$attachment->contentId = $partStructure->ifid ? trim($partStructure->id, " <>") : null;
			$attachment->name = $fileName;
			$attachment->disposition = (isset($partStructure->disposition) ? $partStructure->disposition : null);
			if($this->attachmentsDir) {
				$replace = array(
					'/\s/' => '_',
					'/[^0-9a-zа-яіїє_\.]/iu' => '',
					'/_+/' => '_',
					'/(^_)|(_$)/' => '',
				);
				$fileSysName = preg_replace('~[\\\\/]~', '', $mail->id . '_' . $attachmentId . '_' . preg_replace(array_keys($replace), $replace, $fileName));
				$attachment->filePath = $this->attachmentsDir . DIRECTORY_SEPARATOR . $fileSysName;

				if(strlen($attachment->filePath) > 255) {
					$ext = pathinfo($attachment->filePath, PATHINFO_EXTENSION);
					$attachment->filePath = substr($attachment->filePath, 0, 255 - 1 - strlen($ext)) . "." . $ext;
				}

				file_put_contents($attachment->filePath, $data);
			}
			$mail->addAttachment($attachment);
		}
		else {
			if(!empty($params['charset'])) {
				$data = $this->convertStringEncoding($data, $params['charset'], $this->serverEncoding);
			}
			if($partStructure->type == 0 && $data) {
				if(strtolower($partStructure->subtype) == 'plain') {
					$mail->textPlain .= $data;
				}
				else {
					$mail->textHtml .= $data;
				}
			}
			elseif($partStructure->type == 2 && $data) {
				$mail->textPlain .= trim($data);
			}
		}
		if(!empty($partStructure->parts)) {
			foreach($partStructure->parts as $subPartNum => $subPartStructure) {
				if($partStructure->type == 2 && $partStructure->subtype == 'RFC822' && (!isset($partStructure->disposition) || $partStructure->disposition !== "attachment")) {
					$this->initMailPart($mail, $subPartStructure, $partNum, $markAsSeen);
				}
				else {
					$this->initMailPart($mail, $subPartStructure, $partNum . '.' . ($subPartNum + 1), $markAsSeen);
				}
			}
		}
	}

	protected function decodeMimeStr($string, $charset = 'utf-8') {
		$newString = '';
		$elements = imap_mime_header_decode($string);
		for($i = 0; $i < count($elements); $i++) {
			if($elements[$i]->charset == 'default') {
				$elements[$i]->charset = 'iso-8859-1';
			}
			$newString .= $this->convertStringEncoding($elements[$i]->text, $elements[$i]->charset, $charset);
		}
		return $newString;
	}

	function isUrlEncoded($string) {
		$hasInvalidChars = preg_match('#[^%a-zA-Z0-9\-_\.\+]#', $string);
		$hasEscapedChars = preg_match('#%[a-zA-Z0-9]{2}#', $string);
		return !$hasInvalidChars && $hasEscapedChars;
	}

	protected function decodeRFC2231($string, $charset = 'utf-8') {
		if(preg_match("/^(.*?)'.*?'(.*?)$/", $string, $matches)) {
			$encoding = $matches[1];
			$data = $matches[2];
			if($this->isUrlEncoded($data)) {
				$string = $this->convertStringEncoding(urldecode($data), $encoding, $charset);
			}
		}
		return $string;
	}

	/**
	 * Converts a string from one encoding to another.
	 * @param string $string
	 * @param string $fromEncoding
	 * @param string $toEncoding
	 * @return string Converted string if conversion was successful, or the original string if not
	 * @throws Exception
	 */
	protected function convertStringEncoding($string, $fromEncoding, $toEncoding) {
		if(!$string || $fromEncoding == $toEncoding) {
			return $string;
		}
		$convertedString = function_exists('iconv') ? @iconv($fromEncoding, $toEncoding . '//IGNORE', $string) : null;
		if(!$convertedString && extension_loaded('mbstring')) {
			$convertedString = @mb_convert_encoding($string, $toEncoding, $fromEncoding);
		}
		if(!$convertedString) {
			throw new Exception('Mime string encoding conversion failed');
		}
		return $convertedString;
	}

	public function __destruct() {
		$this->disconnect();
	}

	protected function setImapPath($imapPath) {
		$this->imapPath = imap_utf7_encode($imapPath);
	}

	/**
	 * Gets imappath
	 * @return string
	 */
	public function getImapPath() {
		return $this->imapPath;
	}

	/**
	 * Get message in MBOX format
	 * @param $mailId
	 * @return string
	 */
	public function getMailMboxFormat($mailId) {
		return imap_fetchheader($this->getImapStream(), $mailId, FT_UID && FT_PREFETCHTEXT) . imap_body($this->getImapStream(), $mailId, FT_UID);
	}

	/**
	 * Get folders list
	 * @param string $search
	 * @return array
	 */
	public function getMailboxes($search = "*") {
		$arr = array();
		if($t = imap_getmailboxes($this->getImapStream(), $this->imapPath, $search)) {
			foreach($t as $item) {
				$arr[] = array(
					"fullpath" => $item->name,
					"attributes" => $item->attributes,
					"delimiter" => $item->delimiter,
					"shortpath" => substr($item->name, strlen($this->imapPath) - strlen("INBOX")),
				);
			}
		}
		return $arr;
	}

	/**
	 * Creates a new mailbox
	 * @param string $mailbox
	 * @return bool
	 * @deprecated
	 */
	public function createCustomMailbox($mailbox = "") {
		return $this->createMailbox($mailbox);
	}
}

class Exception extends \Exception {

}

class ConnectionException extends Exception {

}
