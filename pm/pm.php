<?php
	/*
	 * e107 website system
	 *
	 * Copyright (C) 2008-2013 e107 Inc (e107.org)
	 * Released under the terms and conditions of the
	 * GNU General Public License (http://www.gnu.org/licenses/gpl.txt)
	 *
	 *	PM plugin - main user interface
	 *
	 */


	/**
	 *    e107 Private messenger plugin
	 *
	 * @package    e107_plugins
	 * @subpackage    pm
	 */


	$retrieve_prefs[] = 'pm_prefs';
	if(!defined('e107_INIT'))
	{
		require_once(__DIR__.'/../../class2.php');
	}


	if(!e107::isInstalled('pm'))
	{
		e107::redirect();
		exit;
	}

	e107::css('pm', 'pm.css');
	require_once(e_PLUGIN . 'pm/pm_class.php');
	require_once(e_PLUGIN . 'pm/pm_func.php');
	e107::includeLan(e_PLUGIN . 'pm/languages/'.e_LANGUAGE.'/'.e_LANGUAGE.'.php');
	e107::getScParser();
	// require_once(e_PLUGIN.'pm/shortcodes/batch/pm_shortcodes.php');

	if(!defined('ATTACHMENT_ICON'))
	{
		define('ATTACHMENT_ICON', pm_icon('attach'));
	}

	define('PM_DELETE_ICON', pm_icon('delete'));

	list($pmRoute) = explode('&', e_QUERY, 2);

	$qs = explode('.', $pmRoute);
	$action = varset($qs[0], 'inbox');
	if(!$action)
	{
		$action = 'inbox';
	}

	if(!empty($_GET['mode']))
	{
		$action = $tp->filter($_GET['mode']);
	}
	/*
	if($action == 'textarea' || $action == 'input')
	{
		if($qs[1] == 'pm_to') {
			require_once(e_HANDLER.'user_select_class.php');
			$us = new user_select;
			$us->popup();
			exit;
		}
	}*/

	$pm_proc_id = intval(varset($qs[1], 0));

	// Server-side mailbox search (?folder&pm_search=term): subject, text and name, across all pages.
	$pm_search = trim((string) varset($_GET['pm_search'], ''));
	if(function_exists('mb_strlen') && mb_strlen($pm_search) > 100)
	{
		$pm_search = mb_substr($pm_search, 0, 100);
	}

	$pmIsPost = (isset($_SERVER['REQUEST_METHOD']) && strtoupper($_SERVER['REQUEST_METHOD']) === 'POST');

	if(!$pmIsPost && pm_actionNeedsToken($action) && defined('e_TOKEN') && empty($_GET['e-token']))
	{
		e107::getMessage()->addError(defset('LAN_PM_REFUSED_TOKEN_MISSING', 'Invalid or missing security token.'));
		$action = 'inbox';
		$pm_proc_id = 0;
	}

	//$pm_prefs = $sysprefs->getArray('pm_prefs');

	// Form action for the current route (SEF aware); keeps an active search alive.
	$pmFormAction = pm_url($pmRoute, $pm_search !== '' ? array('pm_search' => $pm_search) : array());

	$pm_prefs = e107::getPlugPref('pm');


	$pm_prefs['perpage'] = (int) varset($pm_prefs['perpage'], 10);


	if(!isset($pm_prefs['pm_class']) || !check_class($pm_prefs['pm_class']))
	{
		require_once(HEADERF);
		$msg = e107::getMessage();
		$msg->addwarning(LAN_PM_12);
        $warning_text = $msg->render();
		$ns->tablerender(LAN_PM, $warning_text);
		require_once(FOOTERF);
		exit;
	}

	//setScVar('pm_handler_shortcodes','pmPrefs', $pm_prefs);
	$pmManager = new pmbox_manager($pm_prefs);


	//setScVar('pm_handler_shortcodes','pmManager', &$pmManager);


	class pm_extended extends private_message
	{

		protected $pmManager = null;

		/**
		 *    Constructor
		 *
		 * @param array $prefs - pref settings for PM plugin
		 * @return none
		 */
		public function __construct($prefs, $manager)
		{
			$this->pmManager = $manager;
			parent::__construct($prefs);
		}


		/**
		 *    Show the 'Send to' form
		 * @param array|int $to_uid - a PM block of message to reply to, or UID of user to send to
		 *
		 * @return string text for display
		 */
		function show_send($to_uid)
		{
		//	 trigger_error('Method ' . __METHOD__ . ' is deprecated. Use e107::serialize() instead.', E_USER_DEPRECATED);
			$pm_info = array();
			$pm_outbox = $this->pmManager->pm_getInfo('outbox');

			if(is_array($to_uid))
			{
				$pm_info = $to_uid;        // We've been passed a 'reply to' PM
				$to_uid = $pm_info['pm_from'];
			}


			if(!empty($to_uid))
			{

				if($this->canSendTo($to_uid) == false)
				{
					return "<div class='alert alert-danger'>" . LAN_PM_114 . "</div>";// sending to this user is not permitted.
				}

				$sql2 = e107::getDb('sql2');
				$row = $sql2->createQueryBuilder()
					->select('user_name')->from('user')
					->where('user_id', (int) $to_uid) //TODO add a check for userclass.
					->fetchRow();
				if($row)
				{
					$pm_info['from_name'] = $row['user_name'];
					$pm_info['pm_from'] = intval($to_uid);
				}
				else
				{
					return "<div class='alert alert-danger'>" . LAN_PM_115 . "</div>";
				}

			}

			//echo "Show_send: {$to_uid} from {$pm_info['from_name']} is happening<br />";

			if($pm_outbox['outbox']['filled'] >= 100)
			{
				return str_replace('{PERCENT}', $pm_outbox['outbox']['filled'], LAN_PM_13);
			}

			//		$tpl_file = THEME.'pm_template.php';
//		include_once(is_readable($tpl_file) ? $tpl_file : e_PLUGIN.'pm/pm_template.php');

			if(THEME_LEGACY === true)
			{
				include_once(THEME . 'pm_template.php');
				$PM_SEND_PM = $this->updateTemplate($PM_SEND_PM);
			}

			if(empty($PM_SEND_PM))
			{
				$PM_SEND_PM = e107::getTemplate('pm', 'pm', 'send');
			}

			$enc = (check_class($this->pmPrefs['attach_class']) ? "enctype='multipart/form-data'" : '');
			//	setScVar('pm_handler_shortcodes','pmInfo', $pm_info);

			$sc = e107::getScBatch('pm', true, 'pm');
			$sc->setVars($pm_info);
			$sc->wrapper('pm');

//		$PM_SEND_PM = $this->updateTemplate($PM_SEND_PM);

			$text = "<form {$enc} method='post' action='" . pm_url() . "' id='dataform'>
		<div><input type='hidden' name='numsent' value='{$pm_outbox['outbox']['total']}' />" .
				e107::getParser()->parseTemplate($PM_SEND_PM, true, $sc) .
				'</div></form>';

			return $text;
		}


		/**
		 *    Show inbox
		 * @param int $start - offset into list
		 *
		 * @return string text for display
		 */

		function show_inbox($start = 0, $search = '')
		{
			global $pmFormAction;
			$tp = e107::getParser();

//		$tpl_file = THEME.'pm_template.php';
//		include(is_readable($tpl_file) ? $tpl_file : e_PLUGIN.'pm/pm_template.php');
			if(THEME_LEGACY === true)
			{
				include_once(THEME . 'pm_template.php');

				// Is updateTemplate really necessary for v2.x templates?
				$PM_INBOX = array();
				$PM_INBOX['start'] = $this->updateTemplate($PM_INBOX_HEADER);
				$PM_INBOX['item'] = $this->updateTemplate($PM_INBOX_TABLE);
				$PM_INBOX['empty'] = $this->updateTemplate($PM_INBOX_EMPTY);
				$PM_INBOX['end'] = $this->updateTemplate($PM_INBOX_FOOTER);
			}



			if(empty($PM_INBOX['item']))
			{
				$PM_INBOX = e107::getTemplate('pm', 'pm', 'inbox');
			}

			$pm_blocks = $this->block_get();
			$pmlist = $this->pm_get_inbox(USERID, $start, $this->pmPrefs['perpage'], $search);

			//	setScVar('pm_handler_shortcodes', 'pmNextPrev', array('start' => $start, 'total' => $pmlist['total_messages']));

			$sc = e107::getScBatch('pm', true, 'pm');
			$sc->pmNextPrev = array('start' => $start, 'total' => $pmlist['total_messages']);
			$sc->wrapper('pm');

// Is updateTemplate really necessary for v2.x templates?
			/*
					$PM_INBOX_HEADER = $this->updateTemplate($PM_INBOX['start']);
					$PM_INBOX_TABLE = $this->updateTemplate($PM_INBOX['item']);
					$PM_INBOX_EMPTY = $this->updateTemplate($PM_INBOX['empty']);
					$PM_INBOX_FOOTER = $this->updateTemplate($PM_INBOX['end']);
			*/
			$txt = "<form method='post' action='" . $pmFormAction . ">";
			$txt .= $tp->parseTemplate($PM_INBOX['start'], true, $sc);

			if($pmlist['total_messages'])
			{
				foreach($pmlist['messages'] as $rec)
				{
					if(trim($rec['pm_subject']) == '')
					{
						$rec['pm_subject'] = '[' . LAN_PM_61 . ']';
					}

					$sc->setVars($rec);
					$txt .= $tp->parseTemplate($PM_INBOX['item'], true, $sc);
				}
			}
			else
			{
				$txt .= $tp->parseTemplate($PM_INBOX['empty'], true, $sc);
			}

			$txt .= $tp->parseTemplate($PM_INBOX['end'], true, $sc);
			$txt .= "</form>";


			return $txt;
		}


		/**
		 *    Show outbox
		 * @param int $start - offset into list
		 *
		 * @return string text for display
		 */
		function show_outbox($start = 0, $search = '')
		{
			global $pmFormAction;
			$tp = e107::getParser();

//		$tpl_file = THEME.'pm_template.php';
//		include(is_readable($tpl_file) ? $tpl_file : e_PLUGIN.'pm/pm_template.php');
			if(THEME_LEGACY === true)
			{
				include_once(THEME . 'pm_template.php');

				// Is updateTemplate really necessary for v2.x templates?
				$PM_OUTBOX = array();
				$PM_OUTBOX['start'] = $this->updateTemplate($PM_OUTBOX_HEADER);
				$PM_OUTBOX['item'] = $this->updateTemplate($PM_OUTBOX_TABLE);
				$PM_OUTBOX['empty'] = $this->updateTemplate($PM_OUTBOX_EMPTY);
				$PM_OUTBOX['end'] = $this->updateTemplate($PM_OUTBOX_FOOTER);
			}

			if(empty($PM_OUTBOX['item']))
			{
				$PM_OUTBOX = e107::getTemplate('pm', 'pm', 'outbox');
			}

			$pmlist = $this->pm_get_outbox(USERID, $start, $this->pmPrefs['perpage'], $search);
			//	setScVar('pm_handler_shortcodes', 'pmNextPrev', array('start' => $start, 'total' => $pmlist['total_messages']));

			$sc = e107::getScBatch('pm', true, 'pm');
			$sc->pmNextPrev = array('start' => $start, 'total' => $pmlist['total_messages']);
			$sc->wrapper('pm');

// Is updateTemplate really necessary for v2.x templates?
			/*
					$PM_OUTBOX_HEADER = $this->updateTemplate($PM_OUTBOX['start']);
					$PM_OUTBOX_TABLE = $this->updateTemplate($PM_OUTBOX['item']);
					$PM_OUTBOX_EMPTY = $this->updateTemplate($PM_OUTBOX['empty']);
					$PM_OUTBOX_FOOTER = $this->updateTemplate($PM_OUTBOX['end']);
			*/
			$txt = "<form method='post' action='" . $pmFormAction . ">";
			$txt .= $tp->parseTemplate($PM_OUTBOX['start'], true, $sc);

			if($pmlist['total_messages'])
			{
				foreach($pmlist['messages'] as $rec)
				{
					if(trim($rec['pm_subject']) == '')
					{
						$rec['pm_subject'] = '[' . LAN_PM_61 . ']';
					}
					//	setScVar('pm_handler_shortcodes','pmInfo', $rec);
					$sc->setVars($rec);
					$txt .= $tp->parseTemplate($PM_OUTBOX['item'], true, $sc);
				}
			}
			else
			{
				$txt .= $tp->parseTemplate($PM_OUTBOX['empty'], true, $sc);
			}
			$txt .= $tp->parseTemplate($PM_OUTBOX['end'], true, $sc);
			$txt .= '</form>';

			return $txt;
		}


		/**
		 *    Show details of a pm
		 * @param int $pmid - DB ID for PM
		 * @param string $comeFrom - inbox|outbox - determines whether inbox or outbox is shown after PM
		 *
		 * @return string text for display
		 */
		function show_pm($pmid, $comeFrom = '')
		{
			$ns = e107::getRender();

//		$tpl_file = THEME.'pm_template.php';
//		include_once(is_readable($tpl_file) ? $tpl_file : e_PLUGIN.'pm/pm_template.php');
			if(THEME_LEGACY === true)
			{
				$PM_SHOW = null;
				include_once(THEME . 'pm_template.php');
				$PM_SHOW = $this->updateTemplate($PM_SHOW);
			}

			if(empty($PM_SHOW))
			{
				$PM_SHOW = e107::getTemplate('pm', 'pm', 'show');
			}

			$pm_info = $this->pm_get($pmid);

			if(!$this->isParticipant($pm_info))
			{
				$ns->tablerender(LAN_PM, LAN_PM_60);
				return null;
			}

			$sc = e107::getScBatch('pm', true, 'pm');
			$sc->setVars($pm_info);
			$sc->wrapper('pm');

			if($pm_info['pm_read'] == 0 && $pm_info['pm_to'] == USERID)
			{    // Inbox
				$now = time();
				$pm_info['pm_read'] = $now;
				$this->pm_mark_read($pmid, $pm_info);
			}

			$sc->pmMode = $comeFrom;

//		$PM_SHOW = $this->updateTemplate($PM_SHOW);

			$txt = e107::getParser()->parseTemplate($PM_SHOW, true, $sc);

			if($comeFrom == 'outbox')
			{
				$bread = array('text' => LAN_PLUGIN_PM_OUTBOX, 'url' => pm_url('outbox'));
			}
			else
			{
				$bread = array('text' => LAN_PLUGIN_PM_INBOX, 'url' => pm_url('inbox'));
			}

			$ns->tablerender(LAN_PM, $this->breadcrumb($bread, '#' . $pmid) . $txt);

			if(!$comeFrom)
			{
				if($pm_info['pm_from'] == USERID)
				{
					$comeFrom = 'outbox';
				}
			}

			// Need to show inbox or outbox from start
			if($comeFrom == 'outbox')
			{    // Show Outbox

				$caption = '';
				if(!deftrue('BOOTSTRAP'))
				{
					$caption .= LAN_PM . " - " . LAN_PLUGIN_PM_OUTBOX;
				}

				$ns->tablerender($caption, $this->show_outbox(), 'PM');
			}
			else
			{    // Show Inbox
				$caption = '';
				if(!deftrue('BOOTSTRAP'))
				{
					$caption .= LAN_PM . " - " . LAN_PLUGIN_PM_INBOX;
				}

				$ns->tablerender($caption, $this->show_inbox(), 'PM');
			}
		}


		/**
		 *    Show list of blocked users
		 * @param int $start - not used at present; offset into list
		 *
		 * @return string text for display
		 */
		public function showBlocked($start = 0)
		{
			global $pmFormAction;
			$tp = e107::getParser();
//		$tpl_file = THEME.'pm_template.php';
//		include(is_readable($tpl_file) ? $tpl_file : e_PLUGIN.'pm/pm_template.php');
			if(THEME_LEGACY === true)
			{
				include_once(THEME . 'pm_template.php');

				// Is updateTemplate really necessary for v2.x templates?
				$PM_BLOCKED = array();
				$PM_BLOCKED['start'] = $this->updateTemplate($PM_BLOCKED_HEADER);
				$PM_BLOCKED['item'] = $this->updateTemplate($PM_BLOCKED_TABLE);
				$PM_BLOCKED['empty'] = $this->updateTemplate($PM_BLOCKED_EMPTY);
				$PM_BLOCKED['end'] = $this->updateTemplate($PM_BLOCKED_FOOTER);
			}

			if(empty($PM_BLOCKED))
			{
				$PM_BLOCKED = e107::getTemplate('pm', 'pm', 'blocked');
			}

			$pmBlocks = $this->block_get_user();            // TODO - handle pagination, maybe (is it likely to be necessary?)

			$sc = e107::getScBatch('pm', true, 'pm');
			$sc->pmBlocks = $pmBlocks;
			$sc->wrapper('pm');

// Is updateTemplate really necessary for v2.x templates?
			/*
					$PM_BLOCKED_HEADER = $this->updateTemplate($PM_BLOCKED['start']);
					$PM_BLOCKED_TABLE  = $this->updateTemplate($PM_BLOCKED['item']);
					$PM_BLOCKED_EMPTY  = $this->updateTemplate($PM_BLOCKED['empty']);
					$PM_BLOCKED_FOOTER  = $this->updateTemplate($PM_BLOCKED['end']);
			*/

			$txt = "<form method='post' action='" . $pmFormAction . ">";
			$txt .= $tp->parseTemplate($PM_BLOCKED['start'], true, $sc);

			if($pmTotalBlocked = count($pmBlocks))
			{
				foreach($pmBlocks as $pmBlocked)
				{
					$sc->pmBlocked = $pmBlocked;
					//	setScVar('pm_handler_shortcodes','pmBlocked', $pmBlocked);
					$txt .= $tp->parseTemplate($PM_BLOCKED['item'], true, $sc);
				}
			}
			else
			{
				$txt .= $tp->parseTemplate($PM_BLOCKED['empty'], true, $sc);
			}
			$txt .= $tp->parseTemplate($PM_BLOCKED['end'], true, $sc);
			$txt .= '</form>';

			return $txt;
		}


		/**
		 *    Send a PM based on $_POST parameters
		 *
		 * @return string text for display
		 */
		function post_pm()
		{
			// print_a($_POST);

			if(!check_class($this->pmPrefs['pm_class']))
			{
				return LAN_PM_12;
			}

			$pm_info = $this->pmManager->pm_getInfo('outbox');

			if($pm_info['outbox']['total'] != $_POST['numsent'])
			{
				return LAN_PM_14;
			}

			if(isset($_POST['pm_userclass']) && ($_POST['pm_userclass'] == e_UC_NOBODY))
			{
				$_POST['pm_userclass'] = false;
			}

			if(isset($_POST['user']))
			{
				$_POST['pm_to'] = $_POST['user'];
			}

			if(isset($_POST['pm_to']))
			{
				$msg = '';
				$sendVars = array();
				if(!empty($_POST['pm_userclass']))
				{
					if(!check_class($this->pmPrefs['opt_userclass']))
					{
						return LAN_PM_15;
					}
					elseif((!check_class($_POST['pm_userclass']) || !check_class($this->pmPrefs['multi_class'])) && !ADMIN)
					{
						return LAN_PM_16;
					}
				}
				else
				{
					$to_array = explode(",", str_replace(" ", "", $_POST['pm_to']));
					$to_array = array_unique($to_array);

					if(count($to_array) == 1)
					{
						$_POST['pm_to'] = $to_array[0];
					}

					if(check_class($this->pmPrefs['multi_class']) && count($to_array) > 1)
					{
						foreach($to_array as $to)
						{
							if($to_info = $this->pm_getuid($to))
							{    // Check whether sender is blocked - if so, add one to count
								if(!e107::getDb()->createQueryBuilder()
									->update('private_msg_block')
									->increment('pm_block_count')
									->where('pm_block_from', USERID)
									->where('pm_block_to', e107::getParser()->toDB($to))
									->execute())
								{
									$sendVars['to_array'][] = $to_info;
								}
							}
						}
					}
					else
					{
						if($to_info = $this->pm_getuid($_POST['pm_to']))
						{
							$sendVars['to_info'] = $to_info;
						}
						else
						{
							return LAN_PM_17;
						}

						if(e107::getDb()->createQueryBuilder()
							->update('private_msg_block')
							->increment('pm_block_count')
							->where('pm_block_from', USERID)
							->where('pm_block_to', $to_info['user_id'])
							->execute())
						{
							return LAN_PM_18 . $to_info['user_name'];
						}
					}
				}

				if(isset($_POST['receipt']) && check_class($this->pmPrefs['receipt_class']))
				{
					$sendVars['receipt'] = $_POST['receipt'];
				}

				$totalsize = strlen(varset($_POST['pm_message'], ''));

				$maxsize = intval($this->pmPrefs['attach_size']) * 1024;

				$attached = array();

				if(isset($_FILES['file_userfile']['name']) && is_array($_FILES['file_userfile']['name']))
				{
					foreach($_FILES['file_userfile']['name'] as $fid => $name)
					{
						$size = $_FILES['file_userfile']['size'][$fid];

						if(strlen($name) && ($maxsize < 1 || $size <= $maxsize))
						{
							$attached[$fid] = $name;
							$totalsize += $size;

							continue;
						}

						if(strlen($name))
						{
							$msg .= str_replace("{FILENAME}", $name, LAN_PM_62) . "<br />";
						}

						foreach(array_keys($_FILES['file_userfile']) as $part)
						{
							unset($_FILES['file_userfile'][$part][$fid]);
						}
					}
				}

				if(intval($this->pmPrefs['pm_limits']) > 0 && !empty($pm_info['outbox']['limit']))
				{
					if($this->pmPrefs['pm_limits'] == '1')
					{
						if($pm_info['outbox']['total'] >= $pm_info['outbox']['limit'])
						{
							return LAN_PM_19;
						}
					}
					else
					{
						if($pm_info['outbox']['size'] + $totalsize / 1024 > $pm_info['outbox']['limit'])
						{
							return LAN_PM_21;
						}
					}
				}


				if(!empty($attached))
				{
					if(check_class($this->pmPrefs['attach_class']))
					{
						$uploaded = $this->processAttachments();

						if(is_array($uploaded))
						{
							$sendVars['uploaded'] = $uploaded;

							foreach($uploaded as $var)
							{
								if(!empty($var['message']))
								{
									$msg .= $var['message'] . "<br />";
								}
							}
						}
					}
					else
					{
						$msg .= LAN_PM_23 . '<br />';
					}
				}

				$sendVars['from_id'] = USERID;
				$sendVars['pm_subject'] = varset($_POST['pm_subject'], '');
				$sendVars['pm_message'] = varset($_POST['pm_message'], '');

				if(!empty($_POST['pm_userclass']))
				{
					$sendVars['pm_userclass'] = $_POST['pm_userclass'];
				}

				return $msg . $this->add($sendVars);
			}
		}


		/**
		 *	Accept the attachments posted with a PM.
		 *
		 *	The random component lands in the stored name. Four decimal digits are
		 *	nine thousand guesses; sixteen hex characters are not. It carries no
		 *	underscore, so send_file()'s explode("_", $fname, 4) reads new and
		 *	already stored names alike.
		 *
		 *	The deny rules go down before the file does, so a directory is never
		 *	left holding an attachment it does not yet cover. They are read by
		 *	Apache alone; see e_file::protectDirectory().
		 *
		 *	@return array as returned by e_file::getUploaded()
		 */
		function processAttachments()
		{
			if(!$this->protectAttachmentPaths(USERID))
			{
				e107::getLog()->add('PM_ADM_12', $this->attachmentDir(USERID), E_LOG_WARNING);

				return array(array('error' => TRUE, 'message' => LAN_PM_116));
			}

			$randnum = e_random::hex(16);
			$type = 'attachment+' . $randnum . '_';

			return e107::getFile()->getUploaded("attachments", $type, array('max_file_count' => 3));
		}


		function breadcrumb($type = '', $other='')
		{
			if(!deftrue('BOOTSTRAP'))
			{
				return null;
			}

			$array = array();
			$array[0] = array('text' => LAN_PM, 'url' => pm_url());

			if(is_string($type))
			{
				$array[1] = array('text' => $type, 'url' => null);
			}
			elseif(is_array($type))
			{
				$array[1] = $type;
			}

			if(!empty($other))
			{
				$array[2] = array('text' => $other, 'url' => null);
			}

			e107::breadcrumb($array);

			return e107::getForm()->breadcrumb($array);

		}


		/**
	 *	Show the drafts list (middle pane).
	 *
	 *	@param	int $start - offset into list
	 *	@return	string HTML for the drafts list
	 */
	function show_drafts($start = 0, $search = '')
	{
			global $pmFormAction;
		$tp = e107::getParser();

		if(THEME_LEGACY === true)
		{
			include_once(THEME . 'pm_template.php');
			$PM_DRAFTS = array();
			$PM_DRAFTS['start'] = $this->updateTemplate($PM_DRAFTS_HEADER);
			$PM_DRAFTS['item']  = $this->updateTemplate($PM_DRAFTS_TABLE);
			$PM_DRAFTS['empty'] = $this->updateTemplate($PM_DRAFTS_EMPTY);
			$PM_DRAFTS['end']   = $this->updateTemplate($PM_DRAFTS_FOOTER);
		}

		if(empty($PM_DRAFTS['item']))
		{
			$PM_DRAFTS = e107::getTemplate('pm', 'pm', 'drafts');
		}

		$draftlist = $this->draft_getList($start, $this->pmPrefs['perpage'], $search);

		$sc = e107::getScBatch('pm', true, 'pm');
		$sc->pmNextPrev = array('start' => $start, 'total' => $draftlist['total_messages']);
		$sc->wrapper('pm');

		$txt = "<form method='post' action='" . $pmFormAction . ">";
		$txt .= $tp->parseTemplate($PM_DRAFTS['start'], true, $sc);

		if($draftlist['total_messages'])
		{
			foreach($draftlist['messages'] as $rec)
			{
				if(trim($rec['pm_subject']) == '')
				{
					$rec['pm_subject'] = '[' . LAN_PM_61 . ']';
				}
				$sc->setVars($rec);
				$txt .= $tp->parseTemplate($PM_DRAFTS['item'], true, $sc);
			}
		}
		else
		{
			$txt .= $tp->parseTemplate($PM_DRAFTS['empty'], true, $sc);
		}

		$txt .= $tp->parseTemplate($PM_DRAFTS['end'], true, $sc);
		$txt .= "</form>";

		return $txt;
	}


	/**
	 *	Show the compose form pre-filled with a draft (right pane).
	 *
	 *	@param	int $draftId
	 *	@return	string HTML for the compose form
	 */
	function show_draft($draftId)
	{
		$draft = $this->draft_get($draftId);
		if(!$draft)
		{
			return "<div class='p-4 text-muted'>" . defset('LAN_PM_137', 'Draft not found') . "</div>";
		}

		$pm_info = array(
			'pm_draft_id' => $draft['pm_draft_id'],
			'pm_is_draft' => true,
			'pm_subject'  => $draft['pm_subject'],
			'pm_text'     => $draft['pm_text'],
			'pm_option'   => $draft['pm_option'],
		);

		if($this->draftIsSingleRecipient($draft['pm_to']))
		{
			$pm_info['pm_from']   = (int) $draft['pm_to'];
			$pm_info['from_name'] = $this->draftToName($draft['pm_to']);
		}

		$pm_outbox = $this->pmManager->pm_getInfo('outbox');

		if(THEME_LEGACY === true)
		{
			include_once(THEME . 'pm_template.php');
			$PM_SEND_PM = $this->updateTemplate($PM_SEND_PM);
		}

		if(empty($PM_SEND_PM))
		{
			$PM_SEND_PM = e107::getTemplate('pm', 'pm', 'send');
		}

		$enc = (check_class($this->pmPrefs['attach_class']) ? "enctype='multipart/form-data'" : '');

		$sc = e107::getScBatch('pm', true, 'pm');
		$sc->setVars($pm_info);
		$sc->wrapper('pm');

		$text = "<form {$enc} method='post' action='" . pm_url() . "' id='dataform'>";
		$text .= "<div><input type='hidden' name='numsent' value='{$pm_outbox['outbox']['total']}' />";
		$text .= e107::getParser()->parseTemplate($PM_SEND_PM, true, $sc);
		$text .= "</div></form>";

		return $text;
	}

/**
	 * 	Render the left-pane folder navigation.
	 */
	public function renderFolderNav($activeFolder = 'inbox')
	{
		$inboxInfo = $this->pmManager->pm_getInfo('inbox');
		$unread = (int) varset($inboxInfo['inbox']['unread'], 0);
		$draftCount = (int) $this->draft_count();

		$blockedCount = (int) e107::getDb()->createQueryBuilder()
			->from('private_msg_block')
			->where('pm_block_to', (int) USERID)
			->count();

		$folders = array(
			array('key'=>'inbox','label'=>LAN_PLUGIN_PM_INBOX,'icon'=>pm_icon('inbox'),'badge'=>$unread,'badgeClass'=>'text-bg-danger','url'=>pm_url('inbox')),
			array('key'=>'outbox','label'=>LAN_PLUGIN_PM_OUTBOX,'icon'=>pm_icon('outbox'),'badge'=>'','badgeClass'=>'','url'=>pm_url('outbox')),
			array('key'=>'drafts','label'=>defset('LAN_PM_135','Drafts'),'icon'=>pm_icon('drafts'),'badge'=>$draftCount,'badgeClass'=>'text-bg-secondary','url'=>pm_url('drafts')),
			array('key'=>'blocked','label'=>defset('LAN_PM_151','Blocked'),'icon'=>pm_icon('blocked'),'badge'=>$blockedCount,'badgeClass'=>'text-bg-warning','url'=>pm_url('blocked')),
		);

		$h = '<div class="pm-sidebar-inner">';
		$h .= '<a class="btn btn-primary w-100 mb-3 pm-new-btn" href="' . pm_url('send') . '">';
		$h .= pm_icon('outbox') . ' ' . defset('LAN_PLUGIN_PM_NEW','New Message') . '</a>';
		$h .= '<ul class="pm-folder-list list-unstyled mb-0">';
		foreach ($folders as $f) {
			$active = ($activeFolder===$f['key'] || ($activeFolder==='send' && $f['key']==='inbox')) ? ' active' : '';
			$badge = $f['badge'] ? '<span class="badge ms-auto ' . $f['badgeClass'] . '">' . $f['badge'] . '</span>' : '';
			$h .= '<li class="mb-1"><a class="pm-folder-link d-flex align-items-center px-2 py-1 rounded small' . $active . '" href="' . $f['url'] . '">';
			$h .= '<span class="me-2">' . $f['icon'] . '</span><span>' . $f['label'] . '</span>' . $badge . '</a></li>';
		}
		$h .= '</ul></div>';
		return $h;
	}

	/**
	 * 	Get message HTML for right pane (without tablerender).
	 */
	public function get_pm_html($pmid, $comeFrom = '')
	{
		$PM_SHOW = null;
		if(THEME_LEGACY === true) { include_once(THEME . 'pm_template.php'); $PM_SHOW = $this->updateTemplate($PM_SHOW); }
		if(empty($PM_SHOW)) { $PM_SHOW = e107::getTemplate('pm', 'pm', 'show'); }
		$pm_info = $this->pm_get($pmid);
		if(!$pm_info || !$this->isParticipant($pm_info)) { return null; }
		if($pm_info['pm_read'] == 0 && $pm_info['pm_to'] == USERID) {
			$pm_info['pm_read'] = time();
			$this->pm_mark_read($pmid, $pm_info);
		}
		$sc = e107::getScBatch('pm', true, 'pm');
		$sc->setVars($pm_info);
		$sc->wrapper('pm');
		$sc->pmMode = $comeFrom;
		return e107::getParser()->parseTemplate($PM_SHOW, true, $sc);
	}

	}


	//$pm =& new private_message;
	$pm = new pm_extended($pm_prefs, $pmManager);

	$message = '';
	$pmSource = '';
	if(isset($_POST['pm_come_from']))
	{
		$pmSource = $tp->toDB($_POST['pm_come_from']);
	}
	elseif(isset($qs[2]))
	{
		$pmSource = $tp->toDB($qs[2]);
	}


	//Auto-delete message, if timeout set in admin
	$del_qry = array();
	$read_timeout = intval($pm_prefs['read_timeout']);
	$unread_timeout = intval($pm_prefs['unread_timeout']);
	if($read_timeout > 0)
	{
		$timeout = time() - ($read_timeout * 86400);
		$del_qry[] = array($timeout, '>');
	}
	if($unread_timeout > 0)
	{
		$timeout = time() - ($unread_timeout * 86400);
		$del_qry[] = array($timeout, '=');
	}
	if(count($del_qry) > 0)
	{
		// Preserve the legacy operator precedence: the original query was
		//   (cond1) OR (cond2) AND (pm_from = U OR pm_to = U)
		// where AND binds tighter than OR, i.e. only the LAST timeout condition
		// is ANDed with the ownership group; any earlier condition is ORed before it.
		$ownerCondition = $del_qry[count($del_qry) - 1];
		$priorConditions = array_slice($del_qry, 0, -1);

		$delQb = $sql->createQueryBuilder();
		$delQb->select('pm_id')->from('private_msg');

		foreach($priorConditions as $cond)
		{
			$delQb->orWhere(function($g) use ($cond) {
				$g->where('pm_sent', '<', $cond[0])
				  ->where('pm_read', $cond[1], 0);
			});
		}

		$delQb->orWhere(function($q) use ($ownerCondition) {
			$q->where('pm_sent', '<', $ownerCondition[0])
			  ->where('pm_read', $ownerCondition[1], 0)
			  ->where(function($owner) {
				  $owner->where('pm_from', USERID)->orWhere('pm_to', USERID);
			  });
		});

		$delList = $delQb->fetchAll();
		foreach($delList as $p)
		{
			$pm->del($p['pm_id'], true);
		}
	}


	if('del' == $action || isset($_POST['pm_delete_selected']))
	{
		if(isset($_POST['pm_delete_selected']))
		{
			foreach(array_keys($_POST['selected_pm']) as $id)
			{
				$message .= LAN_PM_24 . ": {$id} <br />";
				$message .= $pm->del($id);
			}
		}
		if('del' == $action)
		{
			$message = $pm->del($pm_proc_id);
		}
		if($pmSource)
		{
			$action = $pmSource;
		}
		else
		{
			if(substr($_SERVER['HTTP_REFERER'], -5) == 'inbox')
			{
				$action = 'inbox';
			}
			elseif(substr($_SERVER['HTTP_REFERER'], -6) == 'outbox')
			{
				$action = 'outbox';
			}
		}
		$pm_proc_id = 0;
		unset($qs);
	}


	if('delblocked' == $action || isset($_POST['pm_delete_blocked_selected']))
	{
		if(isset($_POST['pm_delete_blocked_selected']))
		{
			foreach(array_keys($_POST['selected_pm']) as $id)
			{
				$message .= LAN_PM_70 . ": {$id} <br />";
				$message .= $pm->block_del($id) . '<br />';
			}
		}
		elseif('delblocked' == $action)
		{
			$message = $pm->block_del($pm_proc_id);
		}
		$action = 'blocked';
		$pm_proc_id = 0;
		unset($qs);
	}


	if('block' == $action)
	{
		$message = $pm->block_add($pm_proc_id);
		$action = 'inbox';
		$pm_proc_id = 0;
	}


	if('unblock' == $action)
	{
		$message = $pm->block_del($pm_proc_id);
		$action = 'inbox';
		$pm_proc_id = 0;
	}

	if('deletedraft' == $action)
	{
		$ok = $pm->draft_delete($pm_proc_id);
		$message = $ok ? defset('LAN_PM_138', 'Draft deleted') : defset('LAN_PM_137', 'Draft not found');
		$action = 'drafts';
		$pm_proc_id = 0;
	}


	if('get' == $action)
	{
		$pm->send_file($pm_proc_id, intval($qs[2]));
		exit;
	}


	require_once(HEADERF);

	if(isset($_POST['postpm']))
	{
		$message = $pm->post_pm();
		$action = 'outbox';
	}

	if(isset($_POST['savedraft']))
	{
		$to      = varset($_POST['pm_to'], '');
		$subject = varset($_POST['pm_subject'], '');
		$message = varset($_POST['pm_message'], '');
		$option  = varset($_POST['receipt'], '');
		$draftId = (int) varset($_POST['draft_id'], 0);

		$ok = $pm->draft_save($to, $subject, $message, $option, $draftId);
		$message = $ok ? defset('LAN_PM_133', 'Draft saved') : defset('LAN_PM_137', 'Could not save draft');
		$action  = 'drafts';
		$pm_proc_id = 0;
	}

	$mes = e107::getMessage();

	if($message != '')
	{
		$mes->add($message);
//	$ns->tablerender('', "<div class='alert alert-block'>". $message."</div>");
	}


		//-----------------------------------------
	//			3-PANE ROUNDcube LAYOUT
	//-----------------------------------------
	$pm_folder = 'inbox';
	switch($action)
	{
		case 'inbox':
		case 'send':
			$pm_folder = 'inbox';
			break;
		case 'show':
		case 'reply':
			$pm_folder = ($pmSource === 'outbox') ? 'outbox' : 'inbox';
			break;
		case 'outbox':
			$pm_folder = 'outbox';
			break;
		case 'drafts':
		case 'draft':
			$pm_folder = 'drafts';
			break;
		case 'blocked':
			$pm_folder = 'blocked';
			break;
		default:
			$pm_folder = 'inbox';
			break;
	}

	// Middle pane
	// List routes (e.g. ?inbox.10) carry the record offset in the 2nd URL segment.
	// Message routes (e.g. ?show.11.inbox) carry a message ID there, so only use it for list actions.
	$list_start = in_array($action, array('inbox', 'outbox', 'drafts', 'blocked'), true) ? max(0, (int) $pm_proc_id) : 0;

	// Opening a message (show/reply): keep the middle pane on the page where that message lives.
	if (in_array($action, array('show', 'reply'), true) && in_array($pm_folder, array('inbox', 'outbox'), true) && $pm_proc_id > 0)
	{
		$list_start = (int) $pm->pm_folderOffset($pm_proc_id, $pm_folder);
	}

	switch($pm_folder)
	{
		case 'outbox':
			$middle_html = $pm->show_outbox($list_start, $pm_search);
			break;
		case 'drafts':
			$middle_html = $pm->show_drafts($list_start, $pm_search);
			break;
		case 'blocked':
			$middle_html = $pm->showBlocked($list_start);
			break;
		default:
			$middle_html = $pm->show_inbox($list_start, $pm_search);
			break;
	}

	// Right pane
	switch($action)
	{
		case 'show':
			$right_html = $pm->get_pm_html((int)$pm_proc_id, $pmSource);
			if ($right_html === null) {
				$right_html = '<div class="p-4 text-muted">' . defset('LAN_PM_60', 'Not permitted') . '</div>';
			}
			break;
		case 'send':
			$right_html = $pm->show_send(0);
			break;
		case 'reply':
			if ($pm_info = $pm->pm_get((int)$pm_proc_id))
			{
				if ($pm_info['pm_to'] == USERID)
				{
					$right_html = $pm->show_send($pm_info);
				}
				else
				{
					$right_html = '<div class="p-4 text-muted">' . defset('LAN_PM_56', 'Cannot reply') . '</div>';
				}
			}
			else
			{
				$right_html = '<div class="p-4 text-muted">' . defset('LAN_PM_57', 'Not found') . '</div>';
			}
			break;
		case 'draft':
			$right_html = $pm->show_draft((int)$pm_proc_id);
			break;
		default:
			$right_html = '<div class="pm-pane-placeholder d-flex align-items-center justify-content-center h-100">'
				. '<div class="text-center text-muted">'
				. '<div class="fs-1 mb-2">' . pm_icon('inbox') . '</div>'
				. '<p class="mb-0">' . defset('LAN_PM_139', 'Select a message') . '</p>'
				. '</div></div>';
			break;
	}

	// Move alerts into the right pane (pane 3) so they don't push the 3-pane layout down.
	$pm_alerts = $mes->render();
	if ($pm_alerts !== '') {
		$right_html = $pm_alerts . $right_html;
	}

	// Compose layout
	$pm_active = ($action === 'send') ? 'send' : $pm_folder;
	$left_html = $pm->renderFolderNav($pm_active);

	// Message search (top of the middle pane) - server-side, searches the whole mailbox (all pages).
	$searchbox = '';
	if(in_array($pm_folder, array('inbox', 'outbox', 'drafts'), true))
	{
		$search_label  = defset('LAN_PM_158', 'Search messages...');
		$search_action = pm_url($pm_folder);
		$searchbox = '<div class="p-2 border-bottom">'
			. '<form method="get" action="' . htmlspecialchars($search_action, ENT_QUOTES, 'UTF-8') . '">'
			. '<input type="search" name="pm_search" id="pm-search" class="form-control pm-search" placeholder="' . $search_label . '" aria-label="' . $search_label . '" value="' . htmlspecialchars($pm_search, ENT_QUOTES, 'UTF-8') . '">'
			. '</form>';

		if($pm_search !== '')
		{
			$pmSearchTotal = null;
			$pmSc = e107::getScBatch('pm', true, 'pm');
			if(isset($pmSc->pmNextPrev['total']))
			{
				$pmSearchTotal = (int) $pmSc->pmNextPrev['total'];
			}
			$pmSearchInfo = ($pmSearchTotal !== null)
				? defset('LAN_PM_160', '{COUNT} message(s) found for "{SEARCH}"')
				: htmlspecialchars($pm_search, ENT_QUOTES, 'UTF-8');
			if($pmSearchTotal !== null)
			{
				// Explicit substitution: defset() parameter formats differ between e107 versions.
				$pmSearchInfo = str_replace(array('{COUNT}', '{SEARCH}'), array((string) $pmSearchTotal, htmlspecialchars($pm_search, ENT_QUOTES, 'UTF-8')), (string) $pmSearchInfo);
			}
			$searchbox .= '<div class="px-3 py-1 d-flex justify-content-between align-items-center small text-muted border-top">'
				. '<span>' . $pmSearchInfo . '</span>'
				. '<a href="' . htmlspecialchars($search_action, ENT_QUOTES, 'UTF-8') . '" class="btn btn-sm btn-outline-secondary text-muted align-self-start" title="' . defset('LAN_PM_159', 'Clear search') . '" aria-label="' . defset('LAN_PM_159', 'Clear search') . '">&times;</a>'
				. '</div>';
		}
		$searchbox .= '</div>';
	}

	$searchjs = "<script>
(function () {
	var input = document.getElementById('pm-search');
	if (!input || !input.form) return;
	var current = input.value.trim();
	var timer = null;
	input.addEventListener('input', function () {
		var v = input.value.trim();
		if (v === current) return;
		if (timer) clearTimeout(timer);
		timer = setTimeout(function () {
			window.location.href = input.form.action + (v !== '' ? '&pm_search=' + encodeURIComponent(v) : '');
		}, 400);
	});
})();

	// Highlight the active message row in pane 2.
	(function () {
		var path = window.location.pathname;
		var anchors = document.querySelectorAll('.pm-row-anchor');
		for (var i = 0; i < anchors.length; i++) {
			if (anchors[i].getAttribute('href') === path) {
				anchors[i].classList.add('active');
			}
		}
	})();
</script>";

	$layout = '<div class="pm-3pane h-100">'
		. '<nav class="pm-sidebar border-end" aria-label="Folders">' . $left_html . '</nav>'
		. '<div class="pm-list border-end d-flex flex-column overflow-auto">' . $searchbox . $middle_html . '</div>'
		. '<div class="pm-pane overflow-auto">' . $right_html . '</div>'
		. '</div>' . $searchjs;

	$ns->tablerender(LAN_PM, $layout, 'PM');



	require_once(FOOTERF);


