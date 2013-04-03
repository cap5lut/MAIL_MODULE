<?php

/**
 * Author:
 *  - Captank (RK2)
 *
 * @Instance
 *
 *	@DefineCommand(
 *		command     = 'mail',
 *		accessLevel = 'guild',
 *		description = 'send a mail',
 *		help        = 'mail.txt'
 *	)
 *	@DefineCommand(
 *		command     = 'checkmails',
 *		accessLevel = 'guild',
 *		description = 'checks mails',
 *		help        = 'mail.txt'
 *	)
 *	@DefineCommand(
 *		command     = 'delmail',
 *		accessLevel = 'guild',
 *		description = 'deletes a mail',
 *		help        = 'mail.txt'
 *	)
 */
class MailController {

	/**
	 * Name of the module.
	 * Set automatically by module loader.
	 */
	public $moduleName;

	/** @Inject */
	public $chatBot;
	
	/** @Inject */
	public $db;
	
	/** @Inject */
	public $text;
	
	/** @Inject */
	public $util;
	
	/** @Inject */
	public $settingManager;
	
	/** @Inject */
	public $accessManager;
	
	/** @Inject */
	public $altsController;
	
	/**
	 * @Setting("maxdays")
	 * @Description("Days until mails get deleted")
	 * @Visibility("edit")
	 * @Type("number")
	 * @Options("5;10;20;30;60")
	 */
	public $defaultMaxDays = "30";
	
	/**
	 * @Setup
	 */
	public function setup() {
		$this->db->loadSQLFile($this->moduleName, "mail");
		$sql = <<<EOD
REPLACE INTO `mails_bots`
	(`name`)
VALUES
	(?);
EOD;
		$this->db->exec($sql, $this->chatBot->vars['name']);
	}
	
	/**
	 * @Event("logon")
	 * @Description("Spam mails on logon")
	 */
	public function spamMailsOnLogon($eventObj) {
		$mails = $this->getMails($sender);
		if($mails !== null) {
			if(count($mails) > 0) {
				$msg = Array();
				foreach($mails as $mail) {
					$msg[] = $this->formatMessage($mail);
				}
				$msg = $this->make_blob('You got mails ('.count($mails).')', implode('<br><br><pagebreak>', $msg));
				$sendto->reply($msg);
			}
		}
	}
	
	/**
	 * @Event("24hrs")
	 * @Description("Deletes old mails")
	 */
	public function deleteLongTimeInactiveRules() {
		$sql = <<<EOD
DELETE FROM
	`mails`
WHERE
	`sendtime` <= ?
EOD;
		$time = time()-24*60*60*intval($this->settingManager->get("maxdays"));
		$this->db->exec($sql, $time);
	}
	
	/**
	 * This command handler shows a mail
	 *
	 * @HandlesCommand("mail")
	 * @Matches("/^mail ([0-9]+)$/i")
	 */
	public function mailShowCommand($message, $channel, $sender, $sendto, $args) {
		$mailstatus = $this->validateMail($args[1], $sender);

		switch($mailstatus) {
			case 1:
					$mail = $this->getMailById($args[1]);
					$msg = $this->formatMessage($mail, true);
					$msg = $this->make_blobl('Mail #'.$mail->id, $msg);
				break;
			case 0:
					$msg = 'Error! Not your mail.';
				break;
			case -1:
					$msg = 'Error! Mail ID doesnt exist.';
				break;
			case -2:
					$msg = 'Error! Character validation failed.';
				break;
		}
		$sendto->reply($msg);
	}
	
	/**
	 * This command handler shows a mail
	 *
	 * @HandlesCommand("checkmails")
	 * @Matches("/^checkmails$/i")
	 */
	public function mailsCheckCommand($message, $channel, $sender, $sendto, $args) {
		$mails = $this->getMails($sender);
		if($mails === null) {
			$msg = 'Error! Character validation failed.';
		}
		else {
			if(count($mails) == 0) {
				$msg = 'No mails.';
			}
			else {
				$msg = Array();
				foreach($mails as $mail) {
					$msg[] = $this->formatMessage($mail);
				}
				$msg = $this->make_blob('Mails ('.count($mails).')', implode('<br><br><pagebreak>', $msg));
			}
		}
		$sendto->reply($msg);
	}
	
	/**
	 * This command handler sends a mail
	 *
	 * @HandlesCommand("mail")
	 * @Matches("/^mail ([a-z][a-z0-9-]+) (.+)$/i")
	 */
	public function mailSendCommand($message, $channel, $sender, $sendto, $args) {
		$result = $this->sendMail($sender, $args[1], $args[2]);
		switch($result) {
			case 1:
					$msg = 'Mail sent.';
				break;
			case -1:
					$msg = 'Error! Sender validation failed.';
				break;
			case -2:
					$msg = 'Error! Invalid recipient!';
				break;
		}
		$sendto->reply($msg);
	}
	
	/**
	 * This command handler delete a mail
	 *
	 * @HandlesCommand("delmail")
	 * @Matches("/^delmail (\d+)$/i")
	 */
	public function mailDeleteCommand($message, $channel, $sender, $sendto, $args) {
		$mailstatus = $this->validateMail($args[1], $sender);

		switch($mailstatus) {
			case 1:
					$sql = <<<EOD
DELETE FROM
	`mails`
WHERE
	`id` = ?
EOD;
					$this->db->exec($sql, $args[1]);
					$msg = 'Mail deleted.';
				break;
			case 0:
					$msg = 'Error! Not your mail.';
				break;
			case -1:
					$msg = 'Error! Mail ID doesnt exist.';
				break;
			case -2:
					$msg = 'Error! Character validation failed.';
				break;
		}
		$sendto->reply($msg);
	}
	
	/**
	 * Get a mail by its ID.
	 *
	 * @param int $id - the mail id
	 * @return mixed - if mail id exists the DBRow, else null
	 */
	public function getMailById($id) {
		$sql = <<<EOD
SELECT
	`id`, `sendtime`, `sender`, `recipient`, `message`
FROM
	`mails`
WHERE
	`id` = ?
LIMIT 1;
EOD;
		$result = $this->db->query($sql, $id);
		if(count($result) == 0) {
			return null;
		}
		else{
			return $result[0];
		}
	}
	
	/**
	 * Get the mails for a character.
	 *
	 * @param string $character - the name of the character
	 * @return array - array of DBRow objects representing the mails or null if invalid character
	 */
	public function getMails($character) {
		$character = ucfirst(strtolower($character));
		if(!$this->validateCharacter) {
			return null;
		}
		
		$alts = $this->getValidatedAlts($character);
		
		if($alts === null) {
			return null;
		}
		
		if(count($alts) == 0) {
			return Array();
		}
		
		$sql = <<<EOD
SELECT
	`id`, `sendtime`, `sender`, `recipient`, `message`
FROM
	`mails`
WHERE
	`recipient` IN (?
EOD;
		$sql .= str_repeat(', ?', count($alts) - 1);
		$sql .= <<<EOD
)
ORDER BY
	`sendtime` DESC;
EOD;
		
		return $this->qb->query($sql, $alts);
	}
	
	/**
	 * This function validates if a mail id is for a character.
	 *
	 * @param int $id - the mail id
	 * @param string $character - the name of the character
	 * @return int - 1 if all is fine, 0 if mail isnt for $character, -1 if mail id is invalid, -2 if character is invalid
	 */
	public function validateMail($id, $character) {
		$character = ucfirst(strtolower($character));
		if(!$this->validateCharacter) {
			return -2;
		}
		$sql = <<<EOD
SELECT
	`id`, `recipient`
FROM
	`mails`
WHERE
	`id` = ?
LIMIT 1;
EOD;
		$mail = $this->db->query($sql, $id);
		if(count($mail) == 0) {
			return -1;
		}
		
		$mail = $mail[0];
		
		$alts = $this->getValidatedAlts($character);
		if($alts === null) {
			return -2;
		}
		
		return in_array($character, $alts) ? 1 : 0;
	}
	
	/**
	 * This function stores the mail in the database.
	 *
	 * @param string $sender - the sender of the mail
	 * @param string $recipient - the recipient of the mail
	 * @param string $message - the mail content
	 * @return int - 1 if all is fine, -1 for invalid sender, -2 for invalid recipient
	 */
	public function sendMail($sender, $recipient, $message) {
		$sender = ucfirst(strtolower($sender));
		$recipient = ucfirst(strtolower($recipient));
		
		if(!$this->validateCharacter($sender)) {
			return -1;
		}
		if(!$this->validateCharacter($recipient)) {
			return -2;
		}
		
		if($this->validateCharacter())
		$sql = <<<EOD
INSERT INTO
	`mails`
	(`sendtime`, `sender`, `recipient`, `message`)
VALUES
	(?, ?, ?, ?)
EOD;
		$this->db->exec($sql, time(), $sender, $recipient, $message);
		return 1;
	}
	
	/**
	 * This function returns all `org_members_%` tables.
	 *
	 * @return array - values are table names.
	 */
	public function getOrgMemberTables() {
		$sql = <<<EOD
SELECT
	`name`
FROM
	`mails_bots`
EOD;
		$bots = $this->db->query($sql);
		$result = Array();
		foreach($bots as $bot) {
			$result[] = 'org_members_'.strtolower($bot->name);
		}
		return $result;
		
	}
	
	/**
	 * Returns validated alts and main as array
	 *
	 * @param string $character - name of the character
	 * @return array - array of alts and main, null if invalid character
	 */
	public function getValidatedAlts($character) {
		$character = ucfirst(strtolower($character));
		if(!$this->validateCharacter($character)) {
			return null;
		}
		
		$altsinfo = $this->altsController->get_alt_info($character);
		$tmp = $alts->get_all_alts();
		$alts = Array();
		foreach($tmp as $alt) {
			if($altsinfo->is_validated($alt))
				$alts[] = $alt;
		}

		return $alts;
	}
	
	/**
	 * This function checks if $character is org mate
	 *
	 * @param string $character - the name of the character
	 * @return boolean - true if valid, false if invalid
	 */
	public function validateCharacter($character) {
		if(!preg_match('~^[a-z][a-z0-9-]{3,24}$~',$character)) {
			return false;
		}

		$accessLevel = $this->accessManager->getAccesslevelForCharacter($character);
		if(AccessManager::$ACCESS_LEVELS[$accessLevel] <= 6 && AccessManager::$ACCESS_LEVELS[$accessLevel] >= 1) {
			return true;
		}
		else {
			$tables = $this->getOrgMemberTables();
			foreach($tables as $table) {
				$sql = <<<EOD
SELECT
	`name`
FROM
	`$table`
WHERE
	`name` = ? AND `mode` = ?
LIMIT 1;
EOD;
				$match = $this->db->query($sql, $character, 'org');
				if(count($match) == 1) {
					return true;
				}
			}
		}
		return false;
	}
	
	/**
	 * This function formats a message for output.
	 *
	 * @param mixed $mail - the DBRow mail object
	 * @param boolean $long - true for full text, optional
	 * @return string - the formated message string
	 */
	public function formatMail($mail, $long = false) {
		if($long) {
			return sprintf("%s <highlight>#%d %s<end><tab>%s<br><tab>%s", $this->util->date($mail->sendtimer), $mail->id, $mail->sender, $this->text->make_chatcmd('delete', '/tell <myname> delmail '.$mail->id), $mail->message);
		}
		else {
			return sprintf("%s <highlight>#%d %s<end> %s", $this->util->date($mail->sendtime), $mail->id, $mail->sender, preg_replace("~^(.{10}[^\\s]*)\\s.*$~","$1 ...", $mail->message));
		}
	}
}
