<?php
/*
 * e107 website system
 *
 * Copyright (C) 2008-2009 e107 Inc (e107.org)
 * Released under the terms and conditions of the
 * GNU General Public License (http://www.gnu.org/licenses/gpl.txt)
 *
 *	PM plugin - shortcodes
 *
 * $URL$
 * $Id$
 */


/**
 *	e107 Private messenger plugin
 *
 *	@package	e107_plugins
 *	@subpackage	pm
 *	@version 	$Id$;
 */


// Note: all shortcodes now begin with 'PM', so some changes from previous versions



//if (!defined('e107_INIT')) { exit; }

e107::includeLan(e_PLUGIN.'pm/languages/'.e_LANGUAGE.'/'.e_LANGUAGE.'.php');
include_once(e_PLUGIN.'pm/pm_func.php');

// register_shortcode('pm_handler_shortcodes', true);
// initShortcodeClass('pm_handler_shortcodes');


/*
PM_FORM_TOUSER	- displays user entry box and selector
PM_FORM_TOCLASS - displays user class selector (when allowed)
PM_FORM_SUBJECT	- displays subject of current PM
PM_FORM_MESSAGE - displays message of current PM
PM_POST_BUTTON
PM_PREVIEW_BUTTON
PM_ATTACHMENT
PM_ATTACHMENT_ICON
PM_ATTACHMENTS
PM_RECEIPT
PM_INBOX_TOTAL
PM_INBOX_UNREAD
PM_INBOX_FILLED
PM_OUTBOX_TOTAL
PM_OUTBOX_UNREAD
PM_OUTBOX_FILLED
PM_DATE
PM_READ
PM_FROM_TO
PM_SUBJECT
PM_FROM
PM_SELECT
PM_READ_ICON
PM_AVATAR
PM_BLOCK_USER
PM_DELETE
PM_DELETE_SELECTED
PM_TO
PM_MESSAGE
PM_REPLY
PM_SEND_PM_LINK
PM_NEWPM_ANIMATE
PM_NEXTPREV
PM_BLOCKED_SENDERS_MANAGE
PM_BLOCKED_SELECT
PM_BLOCKED_USER
PM_BLOCKED_DATE
PM_BLOCKED_DELETE
DELETE_BLOCKED_SELECTED
*/

if(!class_exists('plugin_pm_pm_shortcodes'))
{
	class plugin_pm_pm_shortcodes extends e_shortcode // class pm_handler_shortcodes
	{
		public		$pmPrefs;		// PM system options
		public		$pmInfo;		// Data relating to current PM being displayed - replaced by $var.
		public		$pmBlocks = array();	// Array of blocked users.
		public		$pmBlocked = array();	// Block info when using 'display blocked' page
		public		$nextPrev = array();	//XXX In USE ?? // Variables used by nextprev
		public		$pmManager = NULL;		// Pointer to pmbox_manager class instance
		public		$pmNextPrev = array();
		public      $pmMode = null;
		//public 		$var = array();

		public function __construct()
		{
			$pm_prefs = e107::getPlugPref('pm');
			$this->pmManager = new pmbox_manager($pm_prefs);
			$this->pmPrefs = $pm_prefs;
			// print_a($pm_prefs);
			require_once(e_PLUGIN."pm/pm_class.php");

			$pmClass = new private_message($pm_prefs);

			$blocks = $pmClass->block_get_user();

			foreach($blocks as $usr)
			{
				if($usr['pm_block_to'] == USERID)
				{
					$this->pmBlocks[] = $usr['pm_block_from'];
				}

			}
		}


		public function sc_pm_form_to($parm=null)
		{
			$userTo = $this->sc_pm_form_touser();
			$classTo = $this->sc_pm_form_toclass();

			if(!empty($this->var['pm_from']))
			{
				return e107::getForm()->hidden('pm_to', $this->var['pm_from']).$this->var['from_name'];
			}

			if(deftrue('BOOTSTRAP'))
			{

				if(isset($this->pmPrefs['opt_userclass']) && check_class($this->pmPrefs['opt_userclass']) )
				{
					$tab = array();

					$userDiz = (check_class($this->pmPrefs['multi_class'])) ? LAN_PM_112 : LAN_USER;

					$tab['user'] = array('caption'=> $userDiz, 'text'=>$userTo);
					$tab['class'] = array('caption'=>LAN_PM_4, 'text'=>$classTo);

					return e107::getForm()->tabs($tab);
				}
				else
				{
					return $userTo;
				}



			}

			return $userTo."<br />".$classTo; // Legacy Layout.


		}



		// TODO  Get rid of e107_handlers/user_select_class.php
		public function sc_pm_form_touser()
		{
			if(vartrue($this->var['pm_from']))
			{
				return "<input type='hidden' name='pm_to' value='{$this->var['pm_from']}' />{$this->var['from_name']}";
			}

		//	require_once(e_HANDLER.'user_select_class.php');
		//	$us = new user_select;
		//	$type = ($this->pmPrefs['dropdown'] == TRUE ? 'list' : 'popup');


			if(isset($this->pmPrefs['multi_class']) && check_class($this->pmPrefs['multi_class']))
			{
				$ret = e107::getForm()->userpicker('pm_to', null, array('limit'=>10));
			}
			else
			{
				$frm = e107::getForm();

				$exclude = (getperms('0') && deftrue('e_DEBUG')) ? false : true;

				$ret = $frm->userlist('pm_to',null,array('excludeSelf'=>$exclude, 'default'=>'blank', 'classes'=>varset($this->pmPrefs['send_to_class'], e_UC_MEMBER)));
			}


			return $ret;
		}



		public function sc_pm_form_toclass($parm = '')
		{


			if(vartrue($this->var['from_name']))
			{
				return '';
			}

			$ret = "";

			if(isset($this->pmPrefs['opt_userclass']) && check_class($this->pmPrefs['opt_userclass']) )
			{
				//$ret = "<input type='checkbox' name='to_userclass' value='1' />".LAN_PM_4." ";

		//		$ret = "<div class='input-group'><span class='input-group-addon'>".e107::getForm()->checkbox('to_userclass',1,false, LAN_PM_4)."</span>";

				// Option show by visibility
				$filterVisible = $parm === 'visible' ? 'matchclass, filter' : 'matchclass';

				$args = (ADMIN ? 'nobody, admin, classes' : 'nobody,classes, '.$filterVisible);
				if(check_class($this->pmPrefs['sendall_class']))
				{
					$args = 'member, '.$args;
				}

				$ret .= e107::getUserClass()->uc_dropdown('pm_userclass', e_UC_NOBODY, $args)."</div>";
				if (strpos($ret,'option') === FALSE)  $ret = '';
			}
			else
			{
				$ret = false;
			}


			return $ret;
		}


		public function sc_pm_form_subject()
		{
			$value = '';
			if(vartrue($this->var['pm_subject']))
			{
				$value = $this->var['pm_subject'];
				if(empty($this->var['pm_is_draft']) && substr($value, 0, strlen(LAN_PM_58)) != LAN_PM_58)
				{
					$value = LAN_PM_58.$value;
				}
			}

			return e107::getForm()->text('pm_subject',$value,255, array('size'=>80, 'required'=>1));

			// return "<input class='tbox' type='text' name='pm_subject' value='{$value}' size='63' maxlength='255' />";
		}


		public function sc_pm_form_message()
		{
			$value = '';

			if(vartrue($this->var['pm_text']))
			{
				if(isset($_POST['quote']))
				{
					$author = htmlspecialchars($this->var['from_name'], ENT_QUOTES, 'UTF-8');
					$quoted = e107::getParser()->toHTML($this->var['pm_text'], true);
					$value = "<p><br></p><blockquote><p><em>{$author}</em></p>{$quoted}</blockquote><p><br></p>";
				}
				elseif(vartrue($this->var['pm_is_draft']))
				{
					// Draft edit: reload the saved draft text into the editor.
					// A plain reply (no quote checked) must start with an empty editor.
					$value = $this->var['pm_text'];
				}
			}

			$options = array(
				// WYSIWYG (safe front version, same as submitnews/forum): the site's
				// front-end editor - only members of the 'post_html' class get the
				// HTML editor, everyone else falls back to the safe bbcode text area.
				// Plugin pref 'wysiwyg' = 0 forces the plain text area for everybody.
				'wysiwyg' => (bool) varset($this->pmPrefs['wysiwyg'], 1),
			);

			if(!empty($this->pmPrefs['maxlength']))
			{
				$length = (int) $this->pmPrefs['maxlength'];
				$options['maxlength'] = $length;
				$options['placeholder'] = str_replace('{x}', (string) $length, defset('LAN_PM_122', 'Max. {x} chars.'));
			}

			// bbarea() keeps the field name 'pm_message' (post_pm()/add() unchanged),
			// renders the bb buttons, and the WYSIWYG editor where allowed. WYSIWYG
			// HTML is toDB()'d in add() and shown with toHTML() in sc_pm_message() -
			// the standard e107 content flow (same as submitnews).
			return e107::getForm()->bbarea('pm_message', $value, null, null, 'medium', $options);
		}



		public function sc_pm_post_button()
		{
			return "<input class='button btn btn-primary' type='submit' name='postpm' value='".defset('LAN_PLUGIN_PM_NEW')."' />";
		}


		public function sc_pm_preview_button()
		{
			return "<input class='button btn' type='submit' name='postpm' value='".defset('LAN_PM_3')."' />";
		}


		public function sc_pm_attachment()
		{
			if (isset($this->pmPrefs['attach_class']) && check_class($this->pmPrefs['attach_class']))
			{
				$ret = "
				<div id='up_container' >
					<span id='upline' style='white-space:nowrap'>
					".e107::getForm()->file('file_userfile[]', array('size'=>40, 'multiple'=>'multiple'))."
					</span>
				</div>";
				/*
				$ret .= "
				<input type='button' class='btn btn-default button' value='".LAN_PM_11."' onclick=\"duplicateHTML('upline','up_container');\"  />
				";*/
				return $ret;
			}
			return '';
		}






		public function sc_pm_attachment_icon()
		{
			if(!empty($this->var['pm_attachments']))
			{
				return defset('ATTACHMENT_ICON');
			}
		}


		public function sc_pm_attachments()
		{
			$tp = e107::getParser();

			if(!empty($this->var['pm_attachments']))
			{
				$attachments = explode(chr(0), $this->var['pm_attachments']);
				$i = 0;
				$ret = '';
				foreach($attachments as $a)
				{
					list($timestamp, $fromid, $rand, $filename) = explode("_", $a, 4);
					$url = $this->url('action/get', array('id' => $this->var['pm_id'], 'index' => $i));
					$ret .= "<li>" . pm_icon('attach') . "<a href='".$url."'>{$filename}</a></li>";
					$i++;
				}
			}
			return $ret;
		}


		public function sc_pm_receipt()
		{
			if (isset($this->pmPrefs['receipt_class']) && check_class($this->pmPrefs['receipt_class']))
			{
				return "<input class='me-1' type='checkbox' name='receipt' value='1' />".LAN_PM_10;
			}
			return '';
		}


		public function sc_pm_inbox_total()
		{
			$pm_inbox = $this->pmManager->pm_getInfo('inbox');
			return (int) varset($pm_inbox['inbox']['total']);
		}


		public function sc_pm_inbox_unread()
		{
			$pm_inbox = $this->pmManager->pm_getInfo('inbox');
			return (int) varset($pm_inbox['inbox']['unread']);
		}


		public function sc_pm_inbox_filled()
		{
			$pm_inbox = $this->pmManager->pm_getInfo('inbox');
			return (isset($pm_inbox['inbox']['filled']) && ((int) $pm_inbox['inbox']['filled'] > 0)) ? $pm_inbox['inbox']['filled'] : '';
		}


		public function sc_pm_outbox_total()
		{
			$pm_outbox = $this->pmManager->pm_getInfo('outbox');
			return (int) varset($pm_outbox['outbox']['total']);
		}


		public function sc_pm_outbox_unread()
		{
			$pm_outbox = $this->pmManager->pm_getInfo('outbox');
			return (int) varset($pm_outbox['outbox']['unread']);
		}


		public function sc_pm_outbox_filled()
		{
			$pm_outbox = $this->pmManager->pm_getInfo('outbox');
			return (isset($pm_outbox['outbox']['filled']) && ((int) $pm_outbox['outbox']['filled'] > 0)) ? $pm_outbox['outbox']['filled'] : '';
		}


		public function sc_pm_date($parm = null)
		{
			$tp = e107::getParser();

			if(!empty($parm))
			{
				return $tp->toDate($this->var['pm_sent'], $parm);
			}
			else
			{
				return $tp->toDate($this->var['pm_sent'], 'relative');
			}
		}


		public function sc_pm_read($parm = '')
		{
			if($this->pmMode === 'inbox')
			{
				$ret = '';
			}

			if($this->var['pm_read'] == 0)
			{
				$ret = LAN_PM_27;
			}
			if($this->var['pm_read'] == 1)
			{
				$ret = LAN_PM_28;
			}

			if('lapse' !== $parm)
			{
				$ret = e107::getDate()->convert_date($this->var['pm_read'], $parm);
			}
			else
			{
				$ret = e107::getDate()->computeLapse($this->var['pm_read']);
			}
			
			return $ret; 
		}


		public function sc_pm_from_to()
		{
			$tp = e107::getParser();
		//	$sc = e107::getScBatch('pm',TRUE);

			if($this->pmMode === 'outbox')
			{
				$ret = LAN_PM_2.': ';
				$this->var['user_name'] = $this->var['sent_name'];
				$ret .= $this->sc_pm_to('link'); // $tp->parseTemplate("{PM_TO=link}", false, $sc);
			}
			else
			{
				$ret = LAN_PM_31.': ';
				$this->var['user_name'] = $this->var['from_name'];
				$ret .= $this->sc_pm_from('link');// $tp->parseTemplate("{PM_FROM=link}", false, $sc);
			}
			return $ret;
		}


		public function sc_pm_subject($parm = null)
		{
			$tp = e107::getParser();
			$ret = $tp->toHTML($this->var['pm_subject'], true, 'USER_TITLE');



			if(is_string($parm))
			{
				$prm = explode(',', $parm);
			}
			else
			{
				$prm = $parm;
			}

			if('link' === varset($prm[0]))
			{
				$extra = '';
				// TODO - go with only one route version - view/message ???
				if (isset($prm[1])) $extra = '.'.$prm[1];

				/* Moc: commented because the URL was not rendered correctly. Reverted back to v1.x style.
				if($extra == 'inbox') $url = $this->url('message', 'id='.$this->var['pm_id']);
				elseif($extra == 'outbox') $url = $this->url('sent', 'id='.$this->var['pm_id']);
				else $url = $this->url('show', 'id='.$this->var['pm_id']);

				$ret = "<a href='".$ret."'>".$ret."</a>";
				*/

				$url = pm_url('show.'.$this->var['pm_id'].$extra);

				$ret = "<a href='".$url."'>".$ret."</a>";
			}
			return $ret;
		}


		public function sc_pm_from($parm = '')
		{
			$url = e107::getUrl();

			if('link' === $parm)
			{
				return "<a href='".$url->create('user/profile/view', array('id' => $this->var['pm_from'], 'name' => $this->var['user_name']))."'>{$this->var['user_name']}</a>";
			}
			else
			{
				return $this->var['user_name'];
			}
		}


		public function sc_pm_select()
		{
			return "<input type='checkbox' name='selected_pm[{$this->var['pm_id']}]' value='1' />";
		}


		public function sc_pm_read_icon()
		{
			if($this->var['pm_read'] > 0 )
			{
				return pm_icon('check');
			}
			else
			{
				return pm_icon('dot');
			}
		}

		public function sc_pm_status_class()
		{

			if($this->var['pm_read'] > 0 )
			{
				return 'pm-read';
			}
			else
			{
				return 'pm-unread';
			}

		}


		/**
		 * {PM_ID} - the row's primary id: message id, draft id, or blocked
		 * user id, whichever the current row carries. The JS client uses it
		 * for its open / delete / block / unblock actions.
		 *
		 * @return string
		 */
		public function sc_pm_id()
		{
			$id = varset($this->var['pm_id'], varset($this->var['pm_draft_id'], varset($this->pmBlocked['pm_block_from'], 0)));
			return (string) (int) $id;
		}


		/**
		 * {PM_WHO_ID} - the single-user id an inbox row's block/unblock
		 * action targets (the sender), or 0 when the row is not a
		 * single-user message (class or multi-recipient send).
		 *
		 * @return string
		 */
		public function sc_pm_who_id()
		{
			if(!empty($this->var['pm_from']) && is_numeric($this->var['pm_from']))
			{
				return (string) (int) $this->var['pm_from'];
			}
			if(!empty($this->var['pm_to']) && is_numeric($this->var['pm_to']))
			{
				return (string) (int) $this->var['pm_to'];
			}
			return '0';
		}


		public function sc_pm_avatar($parm=null)
		{
			return e107::getParser()->toAvatar($this->var, $parm);
		}


		public function sc_pm_block_user()
		{



			if(in_array($this->var['pm_from'], $this->pmBlocks))
			{
				$icon = pm_icon('unblock');

				return "<a class='btn  btn-default btn-secondary btn-danger' href='".$this->url('action/unblock', 'id='.$this->var['pm_from'])."' title='".LAN_PM_51."'>".$icon."</a>";
			}
			else
			{
				$icon = pm_icon('block');

				return "<a class='btn  btn-default btn-secondary' href='".$this->url('action/block', 'id='.$this->var['pm_from'])."' title='".LAN_PM_50."'>".$icon."</a>";
			}
		}


		public function sc_pm_delete($parm = '')
		{
			$tp = e107::getParser();

			if($this->pmMode !== 'inbox' && $this->pmMode !== 'outbox' && empty($parm))
			{
				 return '';
			}


			if(!empty($parm))
			{
				$extra = '.'.$parm;
			}
			else
			{
				$extra = '.'.($this->pmMode === 'outbox' ? 'outbox' : 'inbox');
			}


			$action = $extra === '.outbox' ? 'delete-out' : 'delete-in';
			return "<a class='btn btn-default btn-secondary' title='".LAN_DELETE."' href='".$this->url('action/'.$action, 'id='.$this->var['pm_id'])."' onclick=\"return jsconfirm(".$tp->toAttribute($tp->toJSON(LAN_JSCONFIRM)).") \">".PM_DELETE_ICON."</a>";
		}


		public function sc_pm_delete_selected()
		{
			$tp = e107::getParser();
			return e107::getForm()->button('pm_delete_selected',1,'delete',pm_icon('delete').' '.LAN_PM_53);
		//	return "<input type='submit' name='pm_delete_selected' class='button btn btn-sm btn-danger' value='".LAN_PM_53."' />";
		}


		public function sc_pm_to($parm = '')
		{
			if(is_numeric($this->var['pm_to']))
			{
				if('link' === $parm)
				{
					$url = e107::getUrl();
					$ret = "<a href='".$url->create('user/profile/view', array('id' => $this->var['pm_to'], 'name' => $this->var['user_name']))."'>{$this->var['user_name']}</a>";
				}
				else
				{
					$ret = $this->var['user_name'];
				}
			}
			else
			{
				$ret = LAN_PM_63.' '.$this->var['pm_to'];
			}
			
			return $ret; 
		}


		public function sc_pm_message()
		{
			return  e107::getParser()->toHTML($this->var['pm_text'], true);
		}


		public function sc_pm_reply()
		{
			if($this->var['pm_to'] == USERID)
			{
				// pm_id is mapped insisde the config to id key
				$ret = "
				<form method='post' action='".$this->url('action/reply', $this->var)."'>
				<input class='btn btn-primary button' type='submit' name='reply' value='".LAN_PM_55."' />&nbsp;&nbsp;&nbsp;<input type='checkbox' name='quote' /> ".LAN_PM_54."
				</form>
				";
				return $ret;
			}
		}

		/**
		 * @example {PM_SEND_PM_LINK}
		 * @bcfix If broken on old theme try {PM_SEND_PM_LINK=button}
		 * @param null $parm
		 * @return string
		 */
		public function sc_pm_send_pm_link($parm=null)
		{
			$pm_outbox = $this->pmManager->pm_getInfo('outbox');
			if($pm_outbox['outbox']['filled'] < 100)
			{
				$link = $this->url('action/new');
				return ($parm === 'button') ? "<a class='btn btn-mini btn-xs btn-primary' href='{$link}'>".LAN_PLUGIN_PM_NEW."</a>" : $link ;
			}
//			return '';
		}


		public function sc_pm_newpm_animate()
		{
			if(!empty($this->pmPrefs['animate']))
			{
				$pm_inbox = $this->pmManager->pm_getInfo('inbox');
				if($pm_inbox['inbox']['new'] > 0)
				{
					return NEWPM_ANIMATION;
				}
			}
			return '';
		}


		/**
		 * {PM_NEXTPREV=inbox}
		 * {PM_NEXTPREV: type=outbox&tmpl_prefix=dropdown}
		 *
		 * @param string|array $parms
		 * @return string|null
		 */
		public function sc_pm_nextprev($parms = null)
		{
			if(empty($this->pmNextPrev['total']))
			{
				return null;
			}

			if(is_string($parms))
			{
				parse_str($parms, $parmsarray);
			}
			else
			{
				$parmsarray = (array) $parms;
			}

			// BC: {PM_NEXTPREV=inbox} names the mailbox as a bare key rather than as type=.
			foreach(array('inbox', 'outbox', 'drafts') as $key)
			{
				if(array_key_exists($key, $parmsarray))
				{
					unset($parmsarray[$key]);
					$parmsarray['type'] = $key;
					break;
				}
			}

			$type   = !empty($parmsarray['type']) ? $parmsarray['type'] : 'inbox';
			// Keep the active mailbox search (?pm_search=...) alive on pager links.
			$pmSearch = trim((string) varset($_GET['pm_search'], ''));

						// Compact pagination: 1 2 … 7 8 9 … 78 with arrow buttons
			$perpage = (int) $this->pmPrefs['perpage'];
			$total   = (int) $this->pmNextPrev['total'];
			$start   = (int) $this->pmNextPrev['start'];
			$totalPages = $perpage > 0 ? (int) ceil($total / $perpage) : 1;
			$currentPage = $perpage > 0 ? (int) floor($start / $perpage) + 1 : 1;

			if ($totalPages <= 1) return null;

			// Build page list: always show 1 and last, window of 3 around current
			$pages = array();
			$pages[] = 1;
			$winStart = max(2, $currentPage - 1);
			$winEnd   = min($totalPages - 1, $currentPage + 1);
			if ($winStart > 2) $pages[] = '…';
			for ($p = $winStart; $p <= $winEnd; $p++) $pages[] = $p;
			if ($winEnd < $totalPages - 1) $pages[] = '…';
			if ($totalPages > 1) $pages[] = $totalPages;

			// Helper: build URL for a given page (1-based)
			$mkUrl = function ($pg) use ($type, $perpage, $pmSearch) {
				$offset = ($pg - 1) * $perpage;
				if ($offset === 0) {
					$u = pm_url($type);
				} else {
					$u = pm_url($type . '.' . $offset);
				}
				if ($pmSearch !== '') {
					$u .= (strpos($u, '?') === false ? '?' : '&') . 'pm_search=' . urlencode($pmSearch);
				}
				return $u;
			};

			$h = '<ul class="pagination pagination-sm mb-2">';

			// Previous arrow
			if ($currentPage > 1) {
				$h .= '<li class="page-item"><a class="page-link" href="' . $mkUrl($currentPage - 1) . '" aria-label="Previous">&laquo;</a></li>';
			} else {
				$h .= '<li class="page-item disabled"><span class="page-link">&laquo;</span></li>';
			}

			// Page numbers
			foreach ($pages as $p) {
				if ($p === '…') {
					$h .= '<li class="page-item disabled"><span class="page-link">…</span></li>';
				} else {
					$cls = ($p === $currentPage) ? ' active' : '';
					$h .= '<li class="page-item' . $cls . '"><a class="page-link" href="' . $mkUrl($p) . '">' . $p . '</a></li>';
				}
			}

			// Next arrow
			if ($currentPage < $totalPages) {
				$h .= '<li class="page-item"><a class="page-link" href="' . $mkUrl($currentPage + 1) . '" aria-label="Next">&raquo;</a></li>';
			} else {
				$h .= '<li class="page-item disabled"><span class="page-link">&raquo;</span></li>';
			}

			$h .= '</ul>';
			return $h;
		}


		public function sc_pm_blocked_senders_manage()
		{
			$sql = e107::getDb();
			$count = $sql->createQueryBuilder()
				->from('private_msg_block')
				->where('pm_block_to', USERID)
				->count();
			if (!$count) return '';
			return LAN_PM_66;
		}


		public function sc_pm_blocked_select()
		{
			if(!isset($this->pmBlocked['pm_block_from']))
			{
				return null;
			}

			return "<input type='checkbox' name='selected_pm[{$this->pmBlocked['pm_block_from']}]' value='1' />";
		}


		public function sc_pm_blocked_user($parm = '')
		{
			if (empty($this->pmBlocked['user_name']))
			{
				$ret = defset('LAN_PM_72', "User deleted"); // define('LAN_PM_72', 'User deleted');
			}
			if('link' === $parm)
			{

				$url = e107::getUrl();
				$ret = "<a href='".$url->create('user/profile/view', array('id' => $this->pmBlocked['pm_block_from'], 'name' => $this->pmBlocked['user_name']))."'>{$this->pmBlocked['user_name']}</a>";
			}
			else
			{
				$ret = varset($this->pmBlocked['user_name']);
			}
			
			return $ret; 
		}


		public function sc_pm_blocked_date($parm='')
		{
			if(empty($this->pmBlocked['pm_block_datestamp']))
			{
				return null;
			}

			return e107::getDate()->convert_date($this->pmBlocked['pm_block_datestamp'], $parm);
		}


		public function sc_pm_blocked_delete()
		{
			if(empty($this->pmBlocked['pm_block_from']))
			{
				return null;
			}

			return "<a href='".$this->url('action/delete-blocked', array('id' => $this->pmBlocked['pm_block_from']))."' title='".LAN_DELETE."'>" . pm_icon('delete') . "</a>";
		}


		public function sc_pm_delete_blocked_selected()
		{
			return "<input type='submit' name='pm_delete_blocked_selected' class='btn btn-default btn-secondary button' value='".LAN_PM_53."' />";
		}


		public function sc_pm_compose()
		{
			$tp = e107::getParser();
			$urlCompose = pm_url('send');

			$class = (!empty($parm['class'])) ? $parm['class'] : 'btn btn-sm btn-primary btn-block-level';

			return "<a class='".$class."' href='".$urlCompose."'>" . pm_icon('edit') . ' '.defset('LAN_PLUGIN_PM_NEW')."</a>";
		}



		/**
	 *	{PM_DRAFT_SUBJECT} / {PM_DRAFT_SUBJECT=link}
	 *
	 *	@param null|string $parm - 'link' wraps the subject in a ?draft.N link
	 *	@return string
	 */
	public function sc_pm_draft_subject($parm = null)
	{
		$tp = e107::getParser();
		$ret = $tp->toHTML(varset($this->var['pm_subject'], ''), true, 'USER_TITLE');

		if('link' === $parm)
		{
			$ret = "<a href='".pm_url('draft'.intval(varset($this->var['pm_draft_id'], 0)))."'>".$ret."</a>";
		}
		return $ret;
	}


	/**
	 *	{PM_DRAFT_TO} - the draft's recipient, resolved to a display name
	 *	by draft_getList() (to_name) or shown verbatim.
	 *
	 *	@return string
	 */
	public function sc_pm_draft_to()
	{
		$tp = e107::getParser();
		return $tp->toHTML(varset($this->var['to_name'], varset($this->var['pm_to'], '')), true);
	}


	/**
	 *	{PM_DRAFT_DATE} - when the draft was last modified (relative)
	 *
	 *	@return string
	 */
	public function sc_pm_draft_date()
	{
		return e107::getParser()->toDate(intval(varset($this->var['pm_modified'], 0)), 'relative');
	}


	/**
	 *	{PM_DRAFT_DELETE} - tokenised GET link that deletes one owned draft
	 *
	 *	@return string
	 */
	public function sc_pm_draft_delete()
	{
		$id = intval(varset($this->var['pm_draft_id'], 0));
		if(!$id)
		{
			return '';
		}
		$label = defset('LAN_PM_134', 'Delete draft');
		$icon  = pm_icon('delete');
		$url   = pm_actionUrl(pm_url('deletedraft.'.$id), '&amp;');

		return "<a class='btn btn-sm btn-outline-secondary' href='".$url."' title='".$label."' aria-label='".$label."'>".$icon."</a>";
	}


	/**
	 *	{PM_DRAFT_HIDDEN} - hidden draft id inside the compose form, so a
	 *	'save draft' submit updates the row the draft was opened from.
	 *
	 *	@return string
	 */
	public function sc_pm_draft_hidden()
	{
		$id = intval(varset($this->var['pm_draft_id'], 0));
		return $id ? "<input type='hidden' name='draft_id' value='".$id."' />" : '';
	}


	/**
	 *	{PM_SAVE_DRAFT_BUTTON} - submits the compose form as a draft save
	 *	(handled by pm.php's savedraft branch). Works with or without JS.
	 *
	 *	@return string
	 */
	public function sc_pm_save_draft_button()
	{
		$label = defset('LAN_PM_132', 'Save draft');
		$icon  = pm_icon('edit').' ';
		return "<button type='submit' name='savedraft' class='btn btn-outline-secondary' value='".$label."'>".$icon.$label."</button>";
	}


	/**
	 *	{PM_BADGE_INBOX} - inbox unread count badge (empty string when zero)
	 *
	 *	@return string
	 */
	public function sc_pm_badge_inbox()
	{
		$n = (int) varset($this->pmManager->pm_getInfo('inbox')['inbox']['unread'], 0);
		return $n > 0 ? "<span class='badge rounded-pill text-bg-primary'>".$n."</span>" : '';
	}


	/**
	 *	{PM_BADGE_OUTBOX} - outbox total badge (empty string when zero)
	 *
	 *	@return string
	 */
	public function sc_pm_badge_outbox()
	{
		$n = (int) varset($this->pmManager->pm_getInfo('outbox')['outbox']['total'], 0);
		return $n > 0 ? "<span class='badge rounded-pill text-bg-secondary'>".$n."</span>" : '';
	}


	/**
	 *	{PM_BADGE_DRAFTS} - draft count badge (empty string when zero)
	 *
	 *	@return string
	 */
	public function sc_pm_badge_drafts()
	{
		$n = (int) e107::getDb()->createQueryBuilder()
			->from('private_msg_draft')
			->where('pm_draft_uid', (int) USERID)
			->count();
		return $n > 0 ? "<span class='badge rounded-pill text-bg-secondary'>".$n."</span>" : '';
	}


	/**
	 *	{PM_BADGE_BLOCKED} - blocked sender count badge (empty string when zero)
	 *
	 *	@return string
	 */
	public function sc_pm_badge_blocked()
	{
		$n = (int) e107::getDb()->createQueryBuilder()
			->from('private_msg_block')
			->where('pm_block_to', (int) USERID)
			->count();
		return $n > 0 ? "<span class='badge rounded-pill text-bg-danger'>".$n."</span>" : '';
	}


	private function url($route, $params = array())
		{

			if(is_string($params))
			{
				parse_str($params,$params);
			}

			if(!isset($params['id']) && isset($params['pm_id'])) $params['id'] = $params['pm_id'];

			if(is_string($route))
			{
				$route = explode('/', $route, 2);
			}

			$base = pm_url_prefix();
			$ret = '';

			switch($route[1])
			{
				case 'index':
				case 'inbox':
					$ret =  $base.'inbox';
					break;
				case 'outbox':
					$ret = $base.'outbox';
					break;
					// we could just remove them all and let only 'message' live
				case 'show':
					$ret = $base.'show.'.$params['id'];
					break;
				case 'message':
					$ret = $base.'show.'.$params['id'].'.inbox';
					break;
				case 'sent':
					$ret = $base.'show.'.$params['id'].'.outbox';
					break;
				case 'reply':
					$ret = $base.'reply.'.$params['id'];
					break;
				case 'new':
					$ret = $base.'send';
					break;
				case 'delete-in':
					$ret = $base.'del.'.$params['id'].'.inbox';
					break;
				case 'delete-out':
					$ret = $base.'del.'.$params['id'].'.outbox';
					break;
				case 'delete-blocked':
					$ret = $base.'delblocked.'.$params['id'];
					break;
				case 'block':
					$ret = $base.'block.'.$params['id'];
					break;
				case 'unblock':
					$ret = $base.'unblock.'.$params['id'];
					break;
				case 'get':
					$ret = $base.'get.'.$params['id'].'.'.$params['index'];
					break;
			}

			return pm_actionUrl($ret, '&amp;');
		}

	}

}


