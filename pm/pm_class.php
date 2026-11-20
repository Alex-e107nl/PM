<?php
/*
 * e107 website system
 *
 * Copyright (C) 2008-2014 e107 Inc (e107.org)
 * Released under the terms and conditions of the
 * GNU General Public License (http://www.gnu.org/licenses/gpl.txt)
 *
 *	PM plugin - base class API
 *
 */

use e107\Database\QueryBuilder;

if (!defined('e107_INIT')) { exit; }

require_once(e_PLUGIN.'pm/pm_crypto.php');

class private_message
{
	/** gen_type of a bulk send still waiting in the queue for the cron task */
	const QUEUE_TYPE = 'pm_bulk';

	/** gen_type of a queued send a cron run has claimed and is part way through inserting */
	const QUEUE_TYPE_RUNNING = 'pm_bulk_running';

	protected 	$e107;
	protected	$pmPrefs;
	protected	$crypto;


	public function prefs()
	{
		return $this->pmPrefs;
	}


	/**
	 *	Constructor
	 *
	 *	@param array $prefs - pref settings for PM plugin
	 *	@return none
	 */
	public	function __construct($prefs=null)
	{
		$this->e107 = e107::getInstance();
		$this->pmPrefs = e107::pref('pm');
		$this->crypto = new pm_crypto();
	}


	/**
	 *	Mark a PM as read
	 *	If flag set, send read receipt to sender
	 *
	 *	@param	int $pm_id - ID of PM
	 *	@param	array $pm_info - PM details
	 *
	 *	@return	none
	 *	
	 *	@todo - 'read_delete' pref doesn't exist - remove code? Or support?
	 */
	function pm_mark_read($pm_id, $pm_info)
	{
		$now = time();
		if($this->pmPrefs['read_delete'])
		{
			$this->del($pm_id);
		}
		else
		{
			e107::getDb()->createQueryBuilder()->update('private_msg')
				->set('pm_read', $now)->where('pm_id', (int) $pm_id)->execute(); // TODO does this work properly?
			if(strpos($pm_info['pm_option'], '+rr') !== FALSE)
			{
				$this->pm_send_receipt($pm_info);
			}
		  	e107::getEvent()->trigger('user_pm_read', $pm_id);
		}
	}


	/**
	 *	Decrypt the subject and body of a PM row in place.
	 *	Legacy (pre-encryption) values are left untouched, so this is safe on
	 *	a table mid-migration. On a decryption failure (missing key / bad tag)
	 *	the field is blanked rather than leaking ciphertext to the screen.
	 *
	 *	@param	array $row a row from private_msg (passed by reference)
	 *	@return void
	 */
			function decryptRow(&$row)
	{
		if(!is_array($row))
		{
			return;
		}
		foreach(array('pm_subject', 'pm_text') as $field)
		{
			if(!isset($row[$field]) || $row[$field] === '')
			{
				continue;
			}
			$value = $row[$field];

			// Strip e107 [html]...[/html] wrapper (added by trumbowyg e_parse toDB)
			// so the base64 payload is recognised + decrypted.
			$strips = 0;
			while ($strips < 3 && substr($value, 0, 6) === '[html]' && substr($value, -7) === '[/html]')
			{
				$value = substr($value, 6, -7);
				$strips++;
			}

			// Loop-decrypt so legacy double-encrypted rows also display as plaintext.
			$plain = $value;
			$depth = 0;
			while ($this->crypto->isEncrypted($plain) && $depth < 5)
			{
				$dec = $this->crypto->decrypt($plain);
				if ($dec === false)
				{
					break;
				}
				$plain = $dec;
				$depth++;
			}
			$row[$field] = ($plain === false) ? '' : $plain;
		}
	}


	/**
	 * Ensure the encryption key (and its protected folder) exists.
	 *
	 * @return string|false current key version, false on failure
	 */
	function cryptoEnsure()
	{
		return $this->crypto->ensureKey();
	}


	/**
	 * Report on the encryption key for the admin screen.
	 *
	 * @return array supported, exists, version, path
	 */
	function cryptoStatus()
	{
		return array(
			'supported' => $this->crypto->supported(),
			'exists'    => $this->crypto->keyExists(),
			'version'   => $this->crypto->currentVersion(),
			'path'      => $this->crypto->keyPath(),
		);
	}


	/*
	 *	Get an existing PM
	 *
	 *	@param	int $pmid - ID of PM in DB
	 *
	 *	@return	boolean|array - FALSE on error, array of PM info on success
	 */
	function pm_get($pmid)
	{
		$qb = e107::getDb()->createQueryBuilder();
		$row = $qb
			->select('pm.*')
			->selectAs('ut.user_image', 'sent_image')
			->selectAs('ut.user_name', 'sent_name')
			->selectAs('uf.user_image', 'from_image')
			->selectAs('uf.user_name', 'from_name')
			->selectAs('uf.user_email', 'from_email')
			->selectAs('ut.user_email', 'to_email')
			->from('private_msg', 'pm')
			->leftJoin('user', 'ut', $qb->expr()->compareColumns('ut.user_id', 'pm.pm_to'))
			->leftJoin('user', 'uf', $qb->expr()->compareColumns('uf.user_id', 'pm.pm_from'))
			->where('pm.pm_id', (int) $pmid)
			->fetchRow();
		if ($row)
		{
			$this->decryptRow($row);
			return $row;
		}
		return FALSE;
	}


	/**
	 *	List offset (page start) of the folder page a message lives on.
	 *
	 * Used by the 3-pane layout so that opening a message (show/reply) keeps
	 * the middle-pane list on the page where that message is shown, instead
	 * of jumping back to page 1.
	 *
	 * @param int    $pmid   message id
	 * @param string $folder 'inbox' or 'outbox'
	 *
	 * @return int record offset to pass to pm_get_inbox() / pm_get_outbox()
	 */
	function pm_folderOffset($pmid, $folder = 'inbox')
	{
		$pmid = (int) $pmid;
		if($pmid <= 0)
		{
			return 0;
		}

		$info = $this->pm_get($pmid);
		if(empty($info) || empty($info['pm_sent']))
		{
			return 0;
		}

		$perpage = (int) $this->pmPrefs['perpage'];
		if($perpage < 2)
		{
			$perpage = 10;
		}

		$sql = e107::getDb();
		$ownerCol = ($folder === 'outbox') ? 'pm_from' : 'pm_to';
		$delCol   = ($folder === 'outbox') ? "pm.pm_sent_del = '0'" : 'pm.pm_read_del = 0';

		$qry = "SELECT COUNT(*) AS cnt FROM `#private_msg` AS pm
		WHERE pm.{$ownerCol} = ".USERID." AND pm.pm_sent > ".(int) $info['pm_sent']." AND {$delCol}";

		$rows = $sql->execute($qry) ? $sql->db_getList() : array();
		// db_getList() is 0-indexed in some e107 builds and 1-indexed in
		// others, so grab the first row key-agnostically.
		$row = !empty($rows) ? reset($rows) : array();
		$newer = (int) varset($row['cnt'], 0);

		return intdiv($newer, $perpage) * $perpage;
	}


	/**
	 *	Whether the current user is the sender or the recipient of a PM
	 *
	 *	pm_to is a varchar, and the outbox copy of a class or multi-recipient
	 *	send carries a user class name or a list of display names in it rather
	 *	than an id, so the comparison is made on strings. A loose comparison
	 *	reads "2024 Newsletter" as the number 2024 on PHP below 8.
	 *
	 *	@param	array|bool $pm_info - a row as returned by pm_get()
	 *
	 *	@return	boolean
	 */
	public function isParticipant($pm_info)
	{
		if(empty($pm_info) || !USERID)
		{
			return FALSE;
		}

		$userId = (string) (int) USERID;
		$to = isset($pm_info['pm_to']) ? (string) $pm_info['pm_to'] : '';
		$from = isset($pm_info['pm_from']) ? (string) $pm_info['pm_from'] : '';

		return $to === $userId || $from === $userId;
	}


	/**
	 *	The directory every member's PM attachments are stored under.
	 *
	 *	Built here rather than asked of e_file::getUserDir(), which answers for
	 *	e_CURRENT_PLUGIN. This class is instantiated from the cron handler, from
	 *	the admin pages, from a shortcode batch rendering the PM menu on any page
	 *	of the site and from the plugin's own setup hook, and in none of those is
	 *	that constant reliably "pm".
	 *
	 *	@return	string with a trailing separator
	 */
	protected function attachmentRoot()
	{
		return e_MEDIA . 'plugins/pm/attachments/';
	}


	/**
	 *	Where e_file::getUploaded() will put a given member's attachments.
	 *
	 *	@param	int $user - member id, zero for a guest
	 *
	 *	@return	string with a trailing separator
	 */
	protected function attachmentDir($user)
	{
		$name = ((int) $user > 0) ? 'user_' . e107::getParser()->leadingZeros((int) $user, 6) : 'anon';

		return $this->attachmentRoot() . $name . '/';
	}


	/**
	 *	The directory releases before the media tree stored attachments in, still
	 *	read when a stored name is resolved and still swept for orphans.
	 *
	 *	@return	string with a trailing separator
	 */
	protected function legacyAttachmentDir()
	{
		return e_PLUGIN . 'pm/attachments/';
	}


	/**
	 *	The file a stored attachment name names, and the name the member gave it.
	 *
	 *	The owning member is read out of the stored name, which upload_handler
	 *	builds as time_userid_random_originalname, so a name carrying no owner or
	 *	an owner who did not send the message resolves to nothing.
	 *
	 *	@param	string $name - one entry of a pm_attachments list
	 *	@param	int $sender - pm_from of the message the list belongs to
	 *
	 *	@return	array|bool 'path' on disk and 'name' as uploaded, FALSE when the name resolves to no file
	 */
	protected function attachmentFile($name, $sender)
	{
		$name = is_string($name) ? trim($name) : '';

		if($name === '' || basename($name) !== $name)
		{
			return FALSE;
		}

		$nameParts = explode('_', $name, 4);

		if(count($nameParts) < 4 || !ctype_digit($nameParts[1]) || (string) $nameParts[1] !== (string) $sender)
		{
			return FALSE;
		}

		foreach(array($this->legacyAttachmentDir(), $this->attachmentDir($nameParts[1])) as $dir)
		{
			if(is_file($dir . $name))
			{
				return array('path' => $dir . $name, 'name' => $nameParts[3]);
			}
		}

		return FALSE;
	}


	/**
	 *	The attachments a pm_attachments list names, in the order the list holds
	 *	them, which is the order a download link's file number counts in.
	 *
	 *	@param	string $list - a pm_attachments value
	 *
	 *	@return	array of stored names, trimmed
	 */
	protected function attachmentList($list)
	{
		return array_map('trim', explode(chr(0), (string) $list));
	}


	/**
	 *	Whether an attachment is still carried by a message other than $pmid,
	 *	the recipients a bulk send has left for the cron task included.
	 *
	 *	One upload is named by every recipient's row and by the outbox copy, so
	 *	the file outlives each of them individually. An unanswerable question
	 *	counts as in use: a query that fails must not license a delete.
	 *
	 *	The queue is read before the messages are, because a run drops its
	 *	claimed row only once the messages it stood for are in the table: read
	 *	the other way round, a delete could miss both.
	 *
	 *	@param	string $name - one entry of a pm_attachments list
	 *	@param	int $pmid - the message being deleted, which does not count
	 *	@param	int $sender - pm_from of that message, and of every row that can name the same file
	 *
	 *	@return	boolean
	 */
	protected function attachmentInUse($name, $pmid, $sender)
	{
		$queued = $this->queuedAttachments();

		if($queued === FALSE || in_array($name, $queued, TRUE))
		{
			return TRUE;
		}

		$sql = e107::getDb();
		$qb = $sql->createQueryBuilder();

		$query = $qb->select('pm_attachments')->from('private_msg')
			->where('pm_from', (int) $sender)
			->where('pm_id', '!=', (int) $pmid)
			->where($qb->expr()->contains('pm_attachments', $name));

		if($query->execute() === FALSE)
		{
			return TRUE;
		}

		while($row = $sql->fetch())
		{
			if(in_array($name, $this->attachmentList($row['pm_attachments']), TRUE))
			{
				return TRUE;
			}
		}

		return FALSE;
	}


	/** @var array|bool|null every attachment the bulk queue still owes, read once per instance */
	private $queuedAttachmentNames = NULL;

	/**
	 *	The attachments the rows a bulk send left for the cron task still owe,
	 *	the row a run has claimed and is part way through inserting included.
	 *
	 *	Read once and kept, because the answer is the same for every attachment
	 *	of every message deleted in the request, and the queue holds one row per
	 *	chunk of a send that can run to thousands of recipients.
	 *
	 *	@return	array|bool stored names, or FALSE when the queue could not be read
	 */
	protected function queuedAttachments()
	{
		if($this->queuedAttachmentNames !== NULL)
		{
			return $this->queuedAttachmentNames;
		}

		$sql = e107::getDb();

		$query = $sql->createQueryBuilder()->select('gen_chardata')->from('generic')
			->whereIn('gen_type', array(self::QUEUE_TYPE, self::QUEUE_TYPE_RUNNING));

		if($query->execute() === FALSE)
		{
			return FALSE;
		}

		$names = array();

		while($row = $sql->fetch())
		{
			$pmInfo = e107::unserialize($row['gen_chardata']);

			if(isset($pmInfo['pm_attachments']))
			{
				$names = array_merge($names, $this->attachmentList($pmInfo['pm_attachments']));
			}
		}

		$this->queuedAttachmentNames = $names;

		return $names;
	}


	/**
	 *	Cover the directory an attachment is about to be stored in.
	 *
	 *	e107 keeps everything under the document root, so a deny rule is all that
	 *	stands between a stored name and whoever can guess it or be handed it.
	 *	The rules go down before the bytes do, and the caller is told whether they
	 *	did, so that nothing is ever stored in a directory that is not covered.
	 *
	 *	The directory holding every member's is covered as well as the member's
	 *	own, because Apache reads a rule in a parent for everything below it and
	 *	the members who never send again are the ones with nothing else.
	 *
	 *	@param	int $user - id of the member whose upload directory to cover
	 *
	 *	@return	boolean true when the member's directory and its parent are covered
	 */
	public function protectAttachmentPaths($user)
	{
		$fl = e107::getFile();
		$root = $this->attachmentRoot();
		$dir = $this->attachmentDir($user);

		if(!is_dir($dir))
		{
			@mkdir($dir, 0755, true);
		}

		if(!is_dir($dir))
		{
			return FALSE;
		}

		$covered = $fl->protectDirectory($root);

		return $fl->protectDirectory($dir) && $covered;
	}


	/**
	 *	Cover every directory this site has ever stored a PM attachment in.
	 *
	 *	Run from the plugin's install and upgrade hooks. Attachments that predate
	 *	these rules have nothing else to write them: covering a directory when the
	 *	plugin next writes into it asks a member to send another attachment before
	 *	the one they already sent is protected, and the sites holding the exposed
	 *	files are exactly the sites whose members are not sending any.
	 *
	 *	@return	boolean true when every directory found is covered
	 */
	public function protectStoredAttachments()
	{
		$fl = e107::getFile();
		$covered = TRUE;

		foreach($this->storedAttachmentDirs() as $dir)
		{
			$covered = $fl->protectDirectory($dir) && $covered;
		}

		if(!$covered)
		{
			e107::getLog()->add('PM_ADM_11', $this->attachmentRoot(), E_LOG_WARNING);
		}

		return $covered;
	}


	/**
	 *	Every attachment directory that exists on this site: the media root, the
	 *	per-member directories under it, and the directory beside the plugin that
	 *	releases predating the media tree wrote to, which send_file() and del()
	 *	still read.
	 *
	 *	@return	array of paths, each with a trailing separator
	 */
	protected function storedAttachmentDirs()
	{
		$root = $this->attachmentRoot();
		$dirs = array();

		foreach(array($root, $this->legacyAttachmentDir()) as $dir)
		{
			if(is_dir($dir))
			{
				$dirs[] = $dir;
			}
		}

		$members = glob($root . '*', GLOB_ONLYDIR);

		if(empty($members))
		{
			return $dirs;
		}

		foreach($members as $dir)
		{
			$dirs[] = rtrim($dir, '/') . '/';
		}

		return $dirs;
	}


	/*
	 *	Send a PM
	 *
	 *	@param	array $vars	- PM information
	 *		['receipt'] - set TRUE if read receipt required
	 *		['uploaded'] - list of attachments (if any) - each is an array('size', 'name')
	 *		['to_userclass'] - set TRUE if sending to a user class
	 *		['to_array'] = array of recipients
	 *		['pm_userclass'] = target user class
	 *		['to_info'] = recipients array of array('user_id', 'user_class')
	 *
	 *	@param	boolean $bulk - TRUE when $vars is a queued row read back from the generic
	 *		table by the cron task, in which case its pm_* keys are the columns to insert.
	 *		Callers passing request data must leave this FALSE.
	 *
	 *	@return	string - text detailing result
	 */
	function add($vars, $bulk = FALSE)
	{

		$tp = e107::getParser();
		$sql = e107::getDb();
		$pmFieldTypes = $sql->getFieldTypes('private_msg');	// field-typed user-data writes -> valuesTyped (byte-identical; pitfall #4)
		$pmsize = 0;
		$attachlist = '';
		$pm_options = '';
		$ret = '';
		$addOutbox = TRUE;
		$timestamp = time();
		$a_list = array();

		$maxSendNow = varset($this->pmPrefs['pm_max_send'],100);	// Maximum number of PMs to send without queueing them
		if ($bulk)
		{	// Doing bulk send off cron task
			$info = array();
			foreach ($vars as $k => $v)
			{
				if (isset($pmFieldTypes[$k]))
				{
					$info[$k] = $v;
					unset($vars[$k]);
				}
			}
			$addOutbox = FALSE;			// Don't add to outbox - was done earlier
		}
		else
		{	// Send triggered by user - may be immediate or bulk dependent on number of recipients
			$vars['options'] = '';
			if(isset($vars['receipt']) && $vars['receipt']) {$pm_options .= '+rr+';	}

			if(isset($vars['uploaded']))
			{
				foreach($vars['uploaded'] as $u)
				{
					if (!isset($u['error']) || !$u['error'])
					{
						$pmsize += $u['size'];
						$a_list[] = $u['name'];
					}
				}
				$attachlist = implode(chr(0), $a_list);
			}
			$pmsize += strlen($vars['pm_message']);

			$pm_subject = trim($tp->toDB($vars['pm_subject']));
			$pm_message = trim($tp->toDB($vars['pm_message']));

			if (!$pm_subject && !$pm_message && !$attachlist)
			{  // Error - no subject, no message body and no uploaded files
				return LAN_PM_65;
			}

			// Encrypt the subject and body before they are stored (or queued for
			// a bulk send). Empty values are kept empty; if encryption fails the
			// send is aborted so plaintext is never written to the database.
			$enc_subject = ($pm_subject === '') ? '' : $this->crypto->encrypt($pm_subject);
			$enc_message = ($pm_message === '') ? '' : $this->crypto->encrypt($pm_message);
			if($enc_subject === false || $enc_message === false)
			{
				e107::getLog()->add('PM_ADM_11', 'PM encryption failed - message not stored', E_LOG_ERROR);
				return defset('LAN_PM_117', 'Your message could not be encrypted and was not sent. Please contact the site administrator.');
			}

			// Most of the pm info is fixed - just need to set the 'to' user on each send
			$info = array(
				'pm_from' => $vars['from_id'],
				'pm_sent' => $timestamp,					/* Date sent */
				'pm_read' => 0,							/* Date read */
				'pm_subject' => $enc_subject,
				'pm_text' => $enc_message,
				'pm_sent_del' => 0,						/* Set when can delete */
				'pm_read_del' => 0,						/* set when can delete */
				'pm_attachments' => $attachlist,
				'pm_option' => $pm_options,				/* Options associated with PM - '+rr' for read receipt */
				'pm_size' => $pmsize
				);

		//	print_a($info);
		//	print_a($vars);
		}

		if(!empty($vars['pm_userclass']) || isset($vars['to_array']))
		{
			if(!empty($vars['pm_userclass']))
			{
				$toclass = e107::getUserClass()->getName($vars['pm_userclass']);
				$tolist = $this->get_users_inclass($vars['pm_userclass']);
				$ret .= LAN_PM_38.": {$toclass}<br />";
				$class = TRUE;
				$info['pm_sent_del'] = 1; // keep the outbox clean and limited to 1 entry when sending to an entire class.
			}
			else
			{
				$tolist = $vars['to_array'];
				$class = FALSE;
			}
			// Sending multiple PMs here. If more than some number ($maxSendNow), need to split into blocks.
			if (count($tolist) > $maxSendNow)
			{
				$totalSend = count($tolist);
				$targets = array_chunk($tolist, $maxSendNow);		// Split into a number of lists, each with the maximum number of elements (apart from the last block, of course)
				unset($tolist);

				$pmInfo = $info;
				$genInfo = array(
					'gen_type' => self::QUEUE_TYPE,
					'gen_datestamp' => time(),
					'gen_user_id' => USERID,
					'gen_ip' => ''
					);
				for ($i = 0; $i < count($targets) - 1; $i++)
				{	// Save the list in the 'generic' table
					$pmInfo['to_array'] = $targets[$i];			// Should be in exactly the right format
					$genInfo['gen_intdata'] = count($targets[$i]);
					$genInfo['gen_chardata'] = e107::serialize($pmInfo,TRUE);
					$sql->createQueryBuilder()->insert('generic')->valuesTyped($genInfo, array('gen_chardata' => 'string'))->execute();	// Don't want any of the clever sanitising now - bound parameters store values verbatim
				}
				$toclass .= ' ['.$totalSend.']';
				$tolist = $targets[count($targets) - 1];		// Send the residue now (means user probably isn't kept hanging around too long if sending lots)
				unset($targets);
			}
			foreach($tolist as $u)
			{
				set_time_limit(30);
				$info['pm_to'] = intval($u['user_id']);		// Sending to a single user now

				$pmOk = $sql->createQueryBuilder()->insert('private_msg')->valuesTyped($info)->execute();
				$pmid = ($pmOk !== false) ? $sql->lastInsertId() : false;	// guard lastInsertId() on execute() success
				if($pmid)
				{
					$info['pm_id'] = $pmid;
					e107::getEvent()->trigger('user_pm_sent', $info);

					unset($info['pm_id']); // prevent it from being used on the next record.

					if($class == FALSE)
					{
						$toclass .= $u['user_name'].', ';
					}
					if(check_class($this->pmPrefs['notify_class'], null, $u['user_id']))
					{
						$vars['to_info'] = $u;
						$vars['pm_sent'] = $timestamp;
						$this->pm_send_notify($u['user_id'], $vars, $pmid, count($a_list));
					}
				}
				else
				{
					$ret .= LAN_PM_39.": {$u['user_name']} <br />";
					e107::getMessage()->addDebug($sql->getLastErrorText());
				}
			}
			if ($addOutbox)
			{
				$info['pm_to'] = $toclass;		// Class info to put into outbox
				$info['pm_sent_del'] = 0;
				$info['pm_read_del'] = 1;
				$pmOk = $sql->createQueryBuilder()->insert('private_msg')->valuesTyped($info)->execute();
				$pmid = ($pmOk !== false) ? $sql->lastInsertId() : false;	// guard lastInsertId() on execute() success
				if(!$pmid)
				{
					$ret .= LAN_PM_41.'<br />';
				}
			}
			
		}
		else
		{	// Sending to a single person
			$info['pm_to'] = intval($vars['to_info']['user_id']);		// Sending to a single user now




			$pmOk = $sql->createQueryBuilder()->insert('private_msg')->valuesTyped($info)->execute();
			$pmid = ($pmOk !== false) ? $sql->lastInsertId() : false;	// guard lastInsertId() on execute() success
			if($pmid)
			{
				$info['pm_id'] = $pmid;
				$info['pm_sent'] = $timestamp;
				e107::getEvent()->trigger('user_pm_sent', $info);


				if(check_class($this->pmPrefs['notify_class'], null, $vars['to_info']['user_id']))
				{
					set_time_limit(20);
					$vars['pm_sent'] = $timestamp;
					$this->pm_send_notify($vars['to_info']['user_id'], $vars, $pmid, count($a_list));
				}
				$ret .= LAN_PM_40.": {$vars['to_info']['user_name']}<br />";
			}
		}
		return $ret;
	}



	/**
	 *	Delete a PM from a user's inbox/outbox.
	 *	PM is only actually deleted from DB once both sender and recipient have marked it as deleted
	 *	When physically deleted, an attachment goes with it unless another message,
	 *	or a bulk send the cron task has yet to insert, still names the same file
	 *
	 *	@param int $pmid - ID of the PM
	 *	@param boolean $force - set to TRUE to force deletion of unread PMs
	 *	@return boolean|string - FALSE if PM not found, or other DB error. String if successful
	 */
	function del($pmid, $force = FALSE)
	{
		$sql = e107::getDb();
		$pmid = (int)$pmid;
		$ret = '';
		$newvals = '';
		$row = $sql->createQueryBuilder()->select('*')->from('private_msg')
			->where('pm_id', $pmid)
			->where(function (QueryBuilder $q)
			{
				$q->where('pm_from', (int) USERID)->orWhere('pm_to', (int) USERID);
			})
			->fetchRow();
		if($row)
		{
			// if user is the receiver of the PM
			if (!$force && ($row['pm_to'] == USERID))
			{
				$newvals = 'pm_read_del = 1';
				$ret .= LAN_PM_42.'<br />';
				if($row['pm_sent_del'] == 1) { $force = TRUE; } // sender has deleted as well, set force to true so the DB record can be deleted
			}

			// if user is the sender of the PM
			if (!$force && ($row['pm_from'] == USERID))
			{
				if($newvals != '') { $force = TRUE; }
				$newvals = 'pm_sent_del = 1';
				$ret .= LAN_PM_43."<br />";
				if($row['pm_read_del'] == 1) { $force = TRUE; } // receiver has deleted as well, set force to true so the DB record can be deleted
			}

			if($force == TRUE)
			{
				// Delete any attachments and remove PM from db
				$attachments = $this->attachmentList($row['pm_attachments']);
				$aCount = array(0,0);
				foreach($attachments as $a)
				{
					$attachment = $this->attachmentFile($a, $row['pm_from']);

					if ($attachment === FALSE || $this->attachmentInUse($a, $pmid, $row['pm_from']))
					{
						continue;
					}

					if (unlink($attachment['path'])) $aCount[0]++; else $aCount[1]++;
				}
				if ($aCount[0] || $aCount[1])
				{

				//	$ret .= str_replace(array('--GOOD--', '--FAIL--'), $aCount, LAN_PM_71).'<br />';
					$ret .= e107::getParser()->lanVars(LAN_PM_71, $aCount);
				}
				$sql->createQueryBuilder()->delete('private_msg')->where('pm_id', $pmid)->execute();
			}
			else
			{
				// $newvals is one of 'pm_read_del = 1' / 'pm_sent_del = 1'; bind the value, validate the column fail-closed.
				$newcol = trim(strtok($newvals, '='));
				if(in_array($newcol, array('pm_read_del', 'pm_sent_del'), true))
				{
					$sql->createQueryBuilder()->update('private_msg')
						->set($newcol, 1)->where('pm_id', $pmid)->execute();
				}
			}
			return $ret;
		}
		return FALSE;
	}

	/**
	 * Convinient url assembling shortcut
	 *//*
	public function url($action, $params = array(), $options = array())
	{
		if(strpos($action, '/') === false) $action = 'view/'.$action;
		e107::getUrl()->create('pm/'.$action, $params, $options);
	}*/

	/**
	 *	Send an email to notify of a PM
	 *
	 *	@param int $uid - not used
	 *	@param array $pmInfo - PM details
	 *	@param int $pmid - ID of PM in database
	 *	@param int $attach_count - number of attachments
	 *
	 *	@return none
	 */
	function pm_send_notify($uid, $pmInfo, $pmid, $attach_count = 0)
	{
	//	require_once(e_HANDLER.'mail.php');
/*
		$tpl_file = THEME.'pm_template.php';

		$PM_NOTIFY = null; // loaded in template below.

		include(is_readable($tpl_file) ? $tpl_file : e_PLUGIN.'pm/pm_template.php');

		$template = $PM_NOTIFY;
*/
		if(THEME_LEGACY)
		{
			include_once(THEME.'pm_template.php');
		}

		if(empty($PM_NOTIFY))
		{
			$PM_NOTIFY = e107::getTemplate('pm', 'pm', 'notify');
		}

		if(empty($PM_NOTIFY)) // BC Fallback.
		{

			$PM_NOTIFY =
			"<div>
			<h4>".defset('LAN_PM_120', 'You have a new message')." {SITENAME}</h4>
			<p>".defset('LAN_PM_121', 'You have a new private message. Please log in to read it.')."</p>
			<div class='text-center'><br />{PM_BUTTON}</div>
			</div>
			";

		}

		$url = function_exists('pm_url') ? pm_url('show.'.$pmid, array(), 'full') : (e107::url('pm','index', null, array('mode'=>'full')).'?show.'.$pmid);

		// Privacy: the notification carries no message content - no sender name,
		// subject, date or attachment count. Only a nudge to log in and read it.
		$data = array();
		$data['SITENAME']  = SITENAME;
		$data['PM_URL']    = $url;
		$data['PM_BUTTON'] = "<a class='btn btn-primary' href='".$url."'>".defset('LAN_PM_113', 'Read Message')."</a>";

		$text = e107::getParser()->simpleParse($PM_NOTIFY, $data);

		$eml = array();
		$eml['email_subject']		= defset('LAN_PM_120', 'You have a new message');
		$eml['send_html']			= true;
		$eml['email_body']			= $text;
		$eml['template']			= 'default';
		$eml['e107_header']			= $pmInfo['to_info']['user_id'];

		if(e107::getEmail()->sendEmail($pmInfo['to_info']['user_email'], $pmInfo['to_info']['user_name'], $eml) === true)
		{
			return true;
		}
		else
		{
			return false;
		}

	}


	/**
	 *	Send PM read receipt
	 *
	 *	@param array $pmInfo - PM details
	 * 	@return boolean
	 */
	function pm_send_receipt($pmInfo) //TODO Add Template and combine with method above..
	{
		require_once(e_HANDLER.'mail.php');
		$subject = LAN_PM_106." ".$pmInfo['sent_name'];

		$pmlink = function_exists('pm_url') ? pm_url('show.'.$pmInfo['pm_id'], array(), 'full') : (e107::url('pm','index', null, array('mode'=>'full')).'?show.'.$pmInfo['pm_id']);

		$txt = str_replace("{UNAME}", $pmInfo['sent_name'], LAN_PM_107)." ".date('l F dS Y h:i:s A')."\n\n";
		$txt .= LAN_PM_108." ".date('l F dS Y h:i:s A', $pmInfo['pm_sent'])."\n";
		// (subject intentionally omitted from the receipt for privacy)
		$txt .= LAN_PM_105."\n".$pmlink."\n";

		if(sendemail($pmInfo['from_email'], $subject, $txt, $pmInfo['from_name']))
		{
			return true;
		}

		return false;
	}


	/**
	 *    Get list of users blocked from sending to a specific user ID.
	 *
	 * @param int|mixed $to - user ID
	 * @return array of blocked users as user IDs
	 */
	function block_get($to = USERID)
	{
		$sql = e107::getDb();
		$to = intval($to);		// Precautionary
		$ret = $sql->createQueryBuilder()->select('pm_block_from')->from('private_msg_block')
			->where('pm_block_to', $to)
			->fetchColumn('pm_block_from');
		return $ret;
	}


	/**
	 *	Get list of users blocked from sending to a specific user ID.
	 *
	 *	@param int $to - user ID
	 *
	 *	@return array of blocked users, including specific user info
	 */
	function block_get_user($to = USERID)
	{
		$sql = e107::getDb();
		$to = intval($to);		// Precautionary
		$qb = $sql->createQueryBuilder();
		$ret = $qb
			->select('pm.*', 'u.user_name')
			->from('private_msg_block', 'pm')
			->leftJoin('user', 'u', $qb->expr()->compareColumns('pm.pm_block_from', 'u.user_id'))
			->where('pm_block_to', $to)
			->fetchAll();
		return $ret;
	}


	/**
	 *	Add a user block
	 *
	 *	@param int $from - sender to block
	 *	@param int $to - user doing the blocking
	 *
	 *	@return string result message
	 */
	function block_add($from, $to = USERID)
	{
		$sql = e107::getDb();
		$from = intval($from);
		$uinfo = $sql->createQueryBuilder()->select('user_name', 'user_perms')->from('user')
			->where('user_id', $from)->fetchRow();
		if($uinfo)
		{
			if (($uinfo['user_perms'] == '0') || ($uinfo['user_perms'] == '0.'))
			{  // Don't allow block of main admin
				return LAN_PM_64;
			}

			if(!$sql->createQueryBuilder()->from('private_msg_block')
				->where('pm_block_from', $from)
				->where('pm_block_to', e107::getParser()->toDB($to))
				->count())
			{
				if($sql->createQueryBuilder()->insert('private_msg_block')->valuesTyped(array(
						'pm_block_from' => $from,
						'pm_block_to' => $to,
						'pm_block_datestamp' => time()
					))->execute() !== false)
				{
					return str_replace('{UNAME}', $uinfo['user_name'], LAN_PM_47);
				}
				else
				{
					return LAN_PM_48;
				}
			}
			else
			{
				return str_replace('{UNAME}', $uinfo['user_name'], LAN_PM_49);
			}
		}
		else
		{
			return LAN_PM_17;
		}
	}



	/**
	 *	Delete user block
	 *
	 *	@param int $from - sender to block
	 *	@param int $to - user doing the blocking
	 *
	 *	@return string result message
	 */
	function block_del($from, $to = USERID)
	{
		$sql = e107::getDb();
		$from = intval($from);
		$uinfo = $sql->createQueryBuilder()->select('user_name')->from('user')
			->where('user_id', $from)->fetchRow();
		if($uinfo)
		{
			$row = $sql->createQueryBuilder()->select('pm_block_id')->from('private_msg_block')
				->where('pm_block_from', $from)->where('pm_block_to', (int) $to)->fetchRow();
			if($row)
			{
				if($sql->createQueryBuilder()->delete('private_msg_block')
					->where('pm_block_id', (int) $row['pm_block_id'])->execute())
				{
					return str_replace('{UNAME}', $uinfo['user_name'], LAN_PM_44);
				}
				else
				{
					return LAN_PM_45;
				}
			}
			else
			{
				return str_replace('{UNAME}', $uinfo['user_name'], LAN_PM_46);
			}
		}
		else
		{
			return LAN_PM_17;
		}
	}


	/**
	 *	Get user ID matching a name
	 *
	 *	@param string var - name to match
	 *
	 *	@return boolean|array - FALSE if no match, array of user info if found
	 */
	function pm_getuid($var)
	{
		$sql = e107::getDb();

		$qb = $sql->createQueryBuilder()
			->select('user_id', 'user_name', 'user_class', 'user_email')->from('user');

		if(is_numeric($var))
		{
			$qb->where('user_id', (int) $var);
		}
		else
		{
		//	$var = strip_if_magic($var);
			$var = str_replace("'", '&#039;', trim($var));		// Display name uses entities for apostrophe
			$qb->where($qb->expr()->like('user_name', $var));
		}

		$row = $qb->fetchRow();
		if($row)
		{
			return $row;
		}

		return false;

	}


	/**
	 *	Get list of users in class
	 *
	 *	@param int $class - class ID
	 *
	 *	@return boolean|array - FALSE on error/none found, else array of user information arrays
	 */
	function get_users_inclass($class)
	{

		return e107::getUserClass()->getUsersInClass($class, 'user_name, user_email, user_class');


		/*$sql = e107::getDb();

		if($class == e_UC_MEMBER)
		{
			$qry = "SELECT user_id, user_name, user_email, user_class FROM `#user` WHERE 1";
		}
		elseif($class == e_UC_ADMIN)
		{
			$qry = "SELECT user_id, user_name, user_email, user_class FROM `#user` WHERE user_admin = 1";
		}
		elseif($class)
		{
			$regex = "(^|,)(".e107::getParser()->toDB($class).")(,|$)";
			$qry = "SELECT user_id, user_name, user_email, user_class FROM `#user` WHERE user_class REGEXP '{$regex}'";
		}


		if(!empty($qry) && $sql->gen($qry))
		{
			$ret = $sql->db_getList();
			return $ret;
		}
		return FALSE;*/
	}


	/**
	 * Check permission to send a PM to someone.
	 * @param int $uid user_id of the person to send to
	 * @return bool
	 */
	function canSendTo($uid)
	{
		if(empty($uid))
		{
			return false;
		}

		if(!empty($this->pmPrefs['vip_class']))
		{
			if(check_class($this->pmPrefs['vip_class'],null,$uid) && !check_class($this->pmPrefs['vip_class']))
			{
				return false;
			}

		}

		$user = e107::user($uid);

		$uclass = explode(",", $user['user_class']);

		if($this->pmPrefs['send_to_class'] == 'matchclass')
		{
			$tmp = explode(",", USERCLASS);
			$result = array_intersect($uclass, $tmp);

			return !empty($result);
		}

		return in_array($this->pmPrefs['send_to_class'], $uclass);

	}




	/**
	 *	Get inbox - up to $limit messages from $from
	 *
	 *	@param int $uid - user ID
	 *	@param int $from - first message
	 *	@param int $limit - number of messages
	 *
	 *	@return boolean|array - FALSE if none found or error, array of PMs if available
	 */
	/**
	 * Case-insensitive search filter for decrypted rows.
	 *
	 * Encrypted messages cannot be searched with SQL LIKE, so when a search
	 * term is active the mailbox is fetched in full, decrypted and filtered here.
	 *
	 * @param array  $rows   decrypted rows
	 * @param string $search search term
	 * @param array  $fields row keys to concatenate for matching
	 *
	 * @return array filtered rows
	 */
	protected function searchFilter(array $rows, $search, array $fields)
	{
		$filtered = array();
		foreach ($rows as $row)
		{
			$hay = '';
			foreach ($fields as $f)
			{
				$hay .= varset($row[$f], '') . ' ';
			}
			if (stripos($hay, $search) !== false)
			{
				$filtered[] = $row;
			}
		}
		return $filtered;
	}


	function pm_get_inbox($uid = USERID, $from = 0, $limit = 10, $search = '')
	{
		$sql = e107::getDb();
		$ret = array();
		$total_messages = 0;
		$uid = intval($uid);
		$limit = intval($limit);
		if ($limit < 2) { $limit = 10; }
		$from = intval($from);
		$search = trim((string) $search);

		if($search !== '')
		{
			// Encrypted text cannot be searched in SQL: fetch all, decrypt, filter in PHP.
			$qry = "
			SELECT pm.*, u.user_image, u.user_name FROM `#private_msg` AS pm
			LEFT JOIN `#user` AS u ON u.user_id = pm.pm_from
			WHERE pm.pm_to = ".(int) $uid." AND pm.pm_read_del = 0
			ORDER BY pm.pm_sent DESC
			";

			if($sql->execute($qry))
			{
				$all = $sql->db_getList();
				foreach($all as &$msg)
				{
					$this->decryptRow($msg);
				}
				unset($msg);
				$all = $this->searchFilter($all, $search, array('pm_subject', 'pm_text', 'user_name'));
				$total_messages = count($all);
				$messages = array_slice($all, $from, $limit);
			}
			else
			{
				$messages = array();
			}

			$ret['messages'] = $messages;
			$ret['total_messages'] = $total_messages;
			return $ret;
		}

		$qry = "
		SELECT SQL_CALC_FOUND_ROWS pm.*, u.user_image, u.user_name FROM `#private_msg` AS pm
		LEFT JOIN `#user` AS u ON u.user_id = pm.pm_from
		WHERE pm.pm_to = ".(int) $uid." AND pm.pm_read_del = 0
		ORDER BY pm.pm_sent DESC
		LIMIT ".(int) $from.", ".(int) $limit."
		";

		if($sql->execute($qry))
		{
			$total_messages = $sql->foundRows(); 		// Total number of messages
			$messages = $sql->db_getList();
			foreach($messages as &$msg)
			{
				$this->decryptRow($msg);
			}
			unset($msg);
			$ret['messages'] = $messages;
		}

		$ret['total_messages'] = $total_messages;		// Should always be defined
		return $ret;
	}


	/**
	 *	Get outbox - up to $limit messages from $from
	 *
	 *	@param int $uid - user ID
	 *	@param int $from - first message
	 *	@param int $limit - number of messages
	 *
	 *	@return boolean|array - FALSE if none found or error, array of PMs if available
	 */
	function pm_get_outbox($uid = USERID, $from = 0, $limit = 10, $search = '')
	{
		$sql = e107::getDb();
		$ret = array();
		$total_messages = 0;
		$uid = intval($uid);
		$limit = intval($limit);
		if ($limit < 2) { $limit = 10; }
		$from = intval($from);
		$search = trim((string) $search);

		if($search !== '')
		{
			// Encrypted text cannot be searched in SQL: fetch all, decrypt, filter in PHP.
			$qry = "
			SELECT pm.*, u.user_image, u.user_name FROM #private_msg AS pm
			LEFT JOIN #user AS u ON u.user_id = pm.pm_to
			WHERE pm.pm_from = ".(int) $uid." AND pm.pm_sent_del = '0'
			ORDER BY pm.pm_sent DESC
			";

			if($sql->execute($qry))
			{
				$all = $sql->db_getList();
				foreach($all as &$msg)
				{
					$this->decryptRow($msg);
				}
				unset($msg);
				$all = $this->searchFilter($all, $search, array('pm_subject', 'pm_text', 'user_name'));
				$total_messages = count($all);
				$messages = array_slice($all, $from, $limit);
			}
			else
			{
				$messages = array();
			}

			$ret['messages'] = $messages;
			$ret['total_messages'] = $total_messages;
			return $ret;
		}

		$qry = "
		SELECT SQL_CALC_FOUND_ROWS pm.*, u.user_image, u.user_name FROM #private_msg AS pm
		LEFT JOIN #user AS u ON u.user_id = pm.pm_to
		WHERE pm.pm_from = ".(int) $uid." AND pm.pm_sent_del = '0'

		ORDER BY pm.pm_sent DESC
		LIMIT ".(int) $from.', '.(int) $limit;

		if($sql->execute($qry))
		{
			$total_messages = $sql->total_results;		// Total number of messages
			$messages = $sql->db_getList();
			foreach($messages as &$msg)
			{
				$this->decryptRow($msg);
			}
			unset($msg);
			$ret['messages'] = $messages;
		}
		$ret['total_messages'] = $total_messages;
		return $ret;
	}


	/**
	 *	Send a file down to the user
	 *
	 *	Only the sender and the recipient of a message may fetch its attachments.
	 *
	 *	@param	int $pmid - PM ID
	 *	@param	string $filenum - attachment number within the list associated with the PM
	 *
	 *	@return	boolean false when there is no file to send, or the caller may not have it
	 *
	 */
	function send_file($pmid, $filenum)
	{
		$pm_info = $this->pm_get($pmid);

		if(!$this->isParticipant($pm_info))
		{
			return false;
		}

		$attachments = $this->attachmentList($pm_info['pm_attachments']);

		if(!isset($attachments[$filenum]))
		{
			return false;
		}

		$attachment = $this->attachmentFile($attachments[$filenum], $pm_info['pm_from']);

		if($attachment === FALSE)
		{
			return false;
		}

		$filename = $attachment['path'];
		$file = $attachment['name'];

	//	e107::getFile()->send($filename); // limited to Media and system folders. Won't work for legacy plugin path.
	//	exit;



		@set_time_limit(10 * 60);
		@ini_set("max_execution_time", 10 * 60);
		while (ob_get_length() !== false)  // destroy all ouput buffering
		{
	        ob_end_clean();
		}

		if (connection_status() == 0)
		{
			if (strpos($_SERVER['HTTP_USER_AGENT'], "MSIE") !== false) {
				$file = preg_replace('/\./', '%2e', $file, substr_count($file, '.') - 1);
			}
			if (isset($_SERVER['HTTP_RANGE']))
			{
				$seek = intval(substr($_SERVER['HTTP_RANGE'] , strlen('bytes=')));
			}
			$bufsize = 2048;
			ignore_user_abort(true);
			$data_len = filesize($filename);
			if ($seek > ($data_len - 1)) $seek = 0;
			$res = fopen($filename, 'rb');
			if ($seek)
			{
				fseek($res , $seek);
			}
			$data_len -= $seek;
			header("Expires: 0");
			header("Cache-Control: max-age=30" );
			header("Content-Type: application/force-download");
			header("Content-Disposition: attachment; filename={$file}");
			header("Content-Length: {$data_len}");
			header("Pragma: public");
			if ($seek)
			{
				header("Accept-Ranges: bytes");
				header("HTTP/1.0 206 Partial Content");
				header("status: 206 Partial Content");
				header("Content-Range: bytes {$seek}-".($data_len - 1)."/{$data_len}");
			}
			while (!connection_aborted() && $data_len > 0)
			{
				echo fread($res , $bufsize);
				$data_len -= $bufsize;
			}
			fclose($res);
		}
	}








	
	function updateTemplate($template)
	{
		$array = array(
		'FORM_TOUSER'		=> 'PM_FORM_TOUSER',
		'FORM_TOCLASS'		=> 'PM_FORM_TOCLASS',
		'FORM_SUBJECT'		=> 'PM_FORM_SUBJECT',
		'FORM_MESSAGE'		=> 'PM_FORM_MESSAGE',
		'EMOTES'			=> 'PM_EMOTES',
		'ATTACHMENT'		=> 'PM_ATTACHMENT',
		'RECEIPT'			=> 'PM_RECEIPT',
		'INBOX_TOTAL'		=> 'PM_INBOX_TOTAL',
		'INBOX_UNREAD'		=> 'PM_INBOX_UNREAD',
		'INBOX_FILLED'		=> 'PM_INBOX_FILLED',
		'OUTBOX_TOTAL'		=> 'PM_OUTBOX_TOTAL',
		'OUTBOX_UNREAD'		=> 'PM_OUTBOX_UNREAD',
		'OUTBOX_FILLED'		=> 'PM_OUTBOX_FILLED',

		'SEND_PM_LINK'		=> 'PM_SEND_PM_LINK',
		'NEWPM_ANIMATE'		=> 'PM_NEWPM_ANIMATE',
	
		'BLOCKED_SENDERS_MANAGE'		=> 'PM_BLOCKED_SENDERS_MANAGE',
		'DELETE_BLOCKED_SELECTED'		=> 'DELETE_BLOCKED_SELECTED'
		);	
		
		
		foreach($array as $old => $new)
		{	
			$template = str_replace("{".$old."}", "{".$new."}", $template);
		}
		
		return $template;
		
	}


	/**
	 *	One-time migration: widen pm_text and encrypt every message still stored
	 *	as plaintext. Idempotent and resumable (cursor on pm_id) - re-run from
	 *	admin maintenance to continue after a long run times out.
	 *
	 *	@return array summary: rows, encrypted, failed, note
	 */
	function migrateEncryptMessages($batch = 200)
	{
		$sql = e107::getDb();
		$sum = array('rows' => 0, 'encrypted' => 0, 'failed' => 0, 'note' => '');

		if(!$this->crypto->supported())
		{
			$sum['note'] = defset('ADLAN_PM_141', 'Encryption is not supported on this PHP/OpenSSL build (needs aes-256-gcm).');
			return $sum;
		}
		if(!$this->crypto->ensureKey())
		{
			$sum['note'] = defset('ADLAN_PM_142', 'Could not create the encryption key. Check write permissions on the pm/crypto folder.');
			return $sum;
		}

		// Widen pm_text so the base64 ciphertext of a long message always fits.
		$types = $sql->getFieldTypes('private_msg');
		if(isset($types['pm_text']) && strtolower($types['pm_text']) === 'text')
		{
			$alt = $sql->gen("ALTER TABLE `#private_msg` MODIFY `pm_text` MEDIUMTEXT NOT NULL");
			if(empty($alt))
			{
				$sum['note'] = defset('ADLAN_PM_144', 'Warning: could not widen pm_text to MEDIUMTEXT; very long messages may be truncated.');
			}
		}

		@set_time_limit(0);
		$lastId = 0;
		do
		{
			$qry = "SELECT pm_id, pm_subject, pm_text FROM `#private_msg` WHERE pm_id > ".(int) $lastId." ORDER BY pm_id ASC LIMIT ".(int) $batch;
			$rows = ($sql->execute($qry)) ? $sql->db_getList() : array();
			if(empty($rows))
			{
				break;
			}
			foreach($rows as $row)
			{
				$sum['rows']++;
				$lastId = (int) $row['pm_id'];

				$qb = $sql->createQueryBuilder()->update('private_msg');
				$changed = FALSE;

				if($row['pm_subject'] !== '' && !$this->crypto->isEncrypted($row['pm_subject']))
				{
					$e = $this->crypto->encrypt($row['pm_subject']);
					if($e === false) { $sum['failed']++; } else { $qb->set('pm_subject', $e); $changed = TRUE; }
				}
				if($row['pm_text'] !== '' && !$this->crypto->isEncrypted($row['pm_text']))
				{
					$e = $this->crypto->encrypt($row['pm_text']);
					if($e === false) { $sum['failed']++; } else { $qb->set('pm_text', $e); $changed = TRUE; }
				}
				if($changed)
				{
					if($qb->where('pm_id', (int) $row['pm_id'])->execute())
					{
						$sum['encrypted']++;
					}
					else
					{
						$sum['failed']++;
					}
				}
			}
		}
		while(count($rows) == (int) $batch);

		if($sum['failed'] == 0 && $sum['note'] === '')
		{
			$sum['note'] = defset('ADLAN_PM_143', 'All messages encrypted.');
		}
		return $sum;
	}

	/**
	 * Rotate the encryption key: append a new key version, then re-encrypt
	 * every stored message under the new key. Fields already encrypted with
	 * the newest version are skipped, so the run is idempotent and resumable.
	 * The previous key is kept in the key file until every row has been
	 * re-encrypted (remove old key lines by hand only after a clean run).
	 *
	 * @param int $batch rows per query
	 * @return array summary: rows, rekeyed, failed, version, note
	 */
	function rotateKey($batch = 200)
	{
		$sum = array('rows' => 0, 'rekeyed' => 0, 'failed' => 0, 'version' => '', 'note' => '');

		if(!$this->crypto->supported())
		{
			$sum['note'] = defset('ADLAN_PM_141', 'Encryption is not supported on this PHP/OpenSSL build (needs aes-256-gcm).');
			return $sum;
		}
		if(!$this->crypto->createKey())
		{
			$sum['note'] = defset('ADLAN_PM_142', 'Could not create the encryption key. Check write permissions on the pm/crypto folder.');
			return $sum;
		}
		$sum['version'] = $this->crypto->currentVersion();

		$sql = e107::getDb();
		@set_time_limit(0);
		$lastId = 0;
		do
		{
			$qry = "SELECT pm_id, pm_subject, pm_text FROM `#private_msg` WHERE pm_id > ".(int) $lastId." ORDER BY pm_id ASC LIMIT ".(int) $batch;
			$rows = ($sql->execute($qry)) ? $sql->db_getList() : array();
			if(empty($rows))
			{
				break;
			}
			foreach($rows as $row)
			{
				$sum['rows']++;
				$lastId = (int) $row['pm_id'];

				$qb = $sql->createQueryBuilder()->update('private_msg');
				$changed = FALSE;
				foreach(array('pm_subject', 'pm_text') as $field)
				{
					$val = (string) $row[$field];
					if($val === '')
					{
						continue;
					}
					if($this->crypto->versionOf($val) === $sum['version'])
					{
						continue;	// already under the newest key
					}
					$plain = $this->crypto->decrypt($val);
					if($plain === false)
					{
						$sum['failed']++;
						continue;
					}
					$e = $this->crypto->encrypt($plain);
					if($e === false)
					{
						$sum['failed']++;
					}
					else
					{
						$qb->set($field, $e);
						$changed = TRUE;
					}
				}
				if($changed)
				{
					if($qb->where('pm_id', (int) $row['pm_id'])->execute())
					{
						$sum['rekeyed']++;
					}
					else
					{
						$sum['failed']++;
					}
				}
			}
		}
		while(count($rows) == (int) $batch);

		if($sum['failed'] == 0)
		{
			$sum['note'] = defset('ADLAN_PM_145', 'Key rotated; all messages re-encrypted under the new key.');
		}
		else
		{
			$sum['note'] = defset('ADLAN_PM_146', 'Key rotated, but some messages could not be re-encrypted. Re-run the rotation to retry.');
		}
		return $sum;
	}


	/**
	 *	------------------------------------------------------------------
	 *	Drafts (D4). Subject and body are encrypted exactly like private_msg,
	 *	and only the owner (pm_draft_uid == USERID) may read or delete them.
	 *	------------------------------------------------------------------
	 */

	/**
	 *	Save a draft (create or update).
	 *
	 *	@param	string $to      - recipient value as submitted (uid / class / name list)
	 *	@param	string $subject - plaintext subject
	 *	@param	string $message - plaintext body
	 *	@param	string $option  - pm_option string (e.g. '+rr')
	 *	@param	int    $draftId - existing draft id to update; 0 to create
	 *
	 *	@return	int|false the draft id on success, false on failure
	 */
	function draft_save($to, $subject, $message, $option = '', $draftId = 0)
	{
		$tp  = e107::getParser();
		$sql = e107::getDb();

		$to      = (string) $to;
		$subject = trim($tp->toDB($subject));
		$message = trim($tp->toDB($message));
		$option  = (string) $option;

		if($subject === '' && $message === '')
		{
			return false;
		}

		// Encrypt before storing; never write plaintext, never write on failure.
		$encSubject = ($subject === '') ? '' : $this->crypto->encrypt($subject);
		$encMessage = ($message === '') ? '' : $this->crypto->encrypt($message);
		if($encSubject === false || $encMessage === false)
		{
			e107::getLog()->add('PM_ADM_11', 'PM draft encryption failed - draft not stored', E_LOG_ERROR);
			return false;
		}

		$now = time();
		// Quota figure: plaintext body length, consistent with add() (which
		// counts the message body, not the subject).
		$size = strlen($message);

		if($draftId > 0)
		{
			// Only update a draft we own.
			$sql->createQueryBuilder()->update('private_msg_draft')
				->set('pm_to', $to)
				->set('pm_subject', $encSubject)
				->set('pm_text', $encMessage)
				->set('pm_option', $option)
				->set('pm_size', $size)
				->set('pm_modified', $now)
				->where('pm_draft_id', (int) $draftId)
				->where('pm_draft_uid', (int) USERID)
				->execute();

			return (int) $draftId;
		}

		$ok = $sql->createQueryBuilder()->insert('private_msg_draft')
			->values(array(
				'pm_draft_uid' => (int) USERID,
				'pm_to'        => $to,
				'pm_subject'   => $encSubject,
				'pm_text'      => $encMessage,
				'pm_option'    => $option,
				'pm_size'      => $size,
				'pm_created'   => $now,
				'pm_modified'  => $now,
			))
			->execute();

		return $ok ? (int) $sql->lastInsertId() : false;
	}


	/**
	 *	Fetch one owned draft, decrypted, for editing.
	 *
	 *	@param	int $draftId
	 *	@return	array|false
	 */
	function draft_get($draftId)
	{
		$sql = e107::getDb();
		$row = $sql->createQueryBuilder()
			->select('pm.*')
			->from('private_msg_draft', 'pm')
			->where('pm.pm_draft_id', (int) $draftId)
			->where('pm.pm_draft_uid', (int) USERID)
			->fetchRow();

		if($row)
		{
			$this->decryptRow($row);
			return $row;
		}
		return false;
	}


	/**
	 *	List the owner's drafts (newest first). The body is not fetched to keep
	 *	the list payload light; the subject is decrypted for display.
	 *
	 *	@param	int $from  - offset
	 *	@param	int $limit - page size
	 *	@return	array { 'messages' => array, 'total_messages' => int }
	 */
	function draft_getList($from = 0, $limit = 10, $search = '')
	{
		$sql = e107::getDb();
		$ret = array('messages' => array(), 'total_messages' => 0);

		$limit = (int) $limit;
		if($limit < 2) { $limit = 10; }
		$from  = (int) $from;
		$search = trim((string) $search);

		if($search !== '')
		{
			// Encrypted text cannot be searched in SQL: fetch all, decrypt, filter in PHP.
			// (Drafts only store the subject in this projection, so subject + recipient are searched.)
			$qry = "SELECT pm_draft_id, pm_to, pm_subject, pm_option, pm_size, pm_created, pm_modified
				FROM `#private_msg_draft`
				WHERE pm_draft_uid = ".(int) USERID."
				ORDER BY pm_modified DESC";

			if($sql->execute($qry))
			{
				$rows = $sql->db_getList();
				foreach($rows as &$row)
				{
					if(!empty($row['pm_subject']))
					{
						$plain = $this->crypto->decrypt($row['pm_subject']);
						$row['pm_subject'] = ($plain === false) ? '' : $plain;
					}
					$row['to_name'] = $this->draftToName($row['pm_to']);
				}
				unset($row);
				$rows = $this->searchFilter($rows, $search, array('pm_subject', 'to_name'));
			}
			else
			{
				$rows = array();
			}

			$ret['messages'] = array_slice($rows, $from, $limit);
			$ret['total_messages'] = count($rows);
			return $ret;
		}

		$total = (int) $sql->createQueryBuilder()
			->from('private_msg_draft')
			->where('pm_draft_uid', (int) USERID)
			->count();

		$qry = "SELECT SQL_CALC_FOUND_ROWS pm_draft_id, pm_to, pm_subject, pm_option, pm_size, pm_created, pm_modified
			FROM `#private_msg_draft`
			WHERE pm_draft_uid = ".(int) USERID."
			ORDER BY pm_modified DESC
			LIMIT ".(int) $from.", ".(int) $limit;

		if($sql->execute($qry))
		{
			$rows = $sql->db_getList();
			foreach($rows as &$row)
			{
				// Decrypt just the subject (pm_text is not in this projection).
				if(!empty($row['pm_subject']))
				{
					$plain = $this->crypto->decrypt($row['pm_subject']);
					$row['pm_subject'] = ($plain === false) ? '' : $plain;
				}
				$row['to_name'] = $this->draftToName($row['pm_to']);
			}
			unset($row);
			$ret['messages'] = $rows;
		}

		$ret['total_messages'] = $total;
		return $ret;
	}


	/**
	 *	Delete one owned draft.
	 *
	 *	@param	int $draftId
	 *	@return	bool
	 */
	function draft_delete($draftId)
	{
		$sql = e107::getDb();
		$ok = $sql->createQueryBuilder()->delete('private_msg_draft')
			->where('pm_draft_id', (int) $draftId)
			->where('pm_draft_uid', (int) USERID)
			->execute();
		return (bool) $ok;
	}


	/**
	 *	Count the owner's drafts.
	 *
	 *	@return	int
	 */
	function draft_count()
	{
		$sql = e107::getDb();
		return (int) $sql->createQueryBuilder()
			->from('private_msg_draft')
			->where('pm_draft_uid', (int) USERID)
			->count();
	}


	/**
	 *	Resolve a draft's pm_to value to a display name.
	 *	A numeric value is a single user id; anything else (a user class name or
	 *	a comma-separated name list) is shown verbatim.
	 *
	 *	@param	string $to
	 *	@return	string
	 */
	function draftToName($to)
	{
		$to = (string) $to;
		if($to === '' || !ctype_digit($to))
		{
			return $to;
		}
		$row = e107::getDb('sql2')->createQueryBuilder()
			->select('user_name')
			->from('user')
			->where('user_id', (int) $to)
			->fetchRow();
		return $row ? $row['user_name'] : ('#' . $to);
	}


	/**
	 *	Whether a draft's pm_to is a single locked user id (as opposed to a
	 *	class or a name list). Used to decide if the recipient field can be
	 *	pre-filled read-only when the draft is opened.
	 *
	 *	@param	string $to
	 *	@return	bool
	 */
	function draftIsSingleRecipient($to)
	{
		return (ctype_digit((string) $to) && (int) $to > 0);
	}

}
