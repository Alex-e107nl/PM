<?php
/*
 * e107 website system
 *
 * Copyright (C) 2008-2014 e107 Inc (e107.org)
 * Released under the terms and conditions of the
 * GNU General Public License (http://www.gnu.org/licenses/gpl.txt)
 *
 *	Private messenger plugin - utility functions
 *
 */

use e107\Database\SqlFragment;

if (!defined('e107_INIT')) { exit; }

class pmbox_manager
{
	protected	$pmPrefs = array();
	protected 	$pmDB;

	public function __construct($prefs='')
	{
		$this->pmDB = e107::getDb();
		// $this->pmPrefs = $prefs;
		
		$this->pmPrefs = e107::pref('pm');
	}


	public function prefs()
	{
		return $this->pmPrefs;

	}


	/**
	 *	Get the box-related information for inbox or outbox - limits, message count etc
	 *	The information read from the DB is cached internally for efficiency
	 *
	 *	@param	string $which = inbox|outbox|clear
	 *
	 *	@return	array
	 *	
	 */
	function pm_getInfo($which = 'inbox')
	{
		static $pm_info;

		if('clear' == $which)
		{
			unset($pm_info['inbox']);
			unset($pm_info['outbox']);
			return;
		}

		if('inbox' == $which)
		{
			$infoQb = $this->pmDB->createQueryBuilder()
				->addSelect(SqlFragment::raw('count(pm.pm_id) AS total, SUM(pm.pm_size)/1024 size, SUM(pm.pm_read = 0) as unread'))
				->from('private_msg', 'pm')
				->where('pm.pm_to', USERID)
				->where('pm.pm_read_del', 0);
		}
		else
		{
			$infoQb = $this->pmDB->createQueryBuilder()
				->addSelect(SqlFragment::raw('count(pm.pm_from) AS total, SUM(pm.pm_size)/1024 size, SUM(pm.pm_read = 0) as unread'))
				->from('private_msg', 'pm')
				->where('pm.pm_from', USERID)
				->where('pm.pm_sent_del', 0);
		}

		if(!isset($pm_info[$which]['total']))
		{
			$pm_info[$which] = $infoQb->fetchRow();
			if ($which == 'inbox' && (!empty($this->pmPrefs['animate']) || !empty($this->pmPrefs['popup'])))
			{
				if($new = $this->pmDB->createQueryBuilder()->from('private_msg')
					->where('pm_sent', '>', USERLV)
					->where('pm_read', 0)
					->where('pm_to', USERID)
					->where('pm_read_del', '!=', 1)
					->count())
				{
					$pm_info['inbox']['new'] = $new;
				}
				else
				{
					$pm_info['inbox']['new'] = 0;
				}
			}
		}

		if(!isset($pm_info[$which]['limit']))
		{
			if(varset($this->pmPrefs['pm_limits'],0) > 0)
			{
				$limitQb = $this->pmDB->createQueryBuilder();
				if($this->pmPrefs['pm_limits'] == 1)
				{
					$limitQb->addSelect($limitQb->raw('MAX(gen_user_id) AS inbox_limit, MAX(CAST(gen_ip AS UNSIGNED)) as outbox_limit'));
				}
				else
				{
					$limitQb->addSelect($limitQb->raw('MAX(gen_intdata) AS inbox_limit, MAX(CAST(gen_chardata AS UNSIGNED)) as outbox_limit'));
				}
				$limitQb->from('generic')
					->where('gen_type', 'pm_limit')
					->where(\e107\Userclass\Membership::current()->predicate('gen_datestamp'));
				if($row = $limitQb->fetchRow())
				{
					$pm_info['inbox']['limit'] =  varset($row['inbox_limit'], '');
					$pm_info['outbox']['limit'] =  varset($row['outbox_limit'], '');
				}
				$pm_info['inbox']['limit_val'] = ($this->pmPrefs['pm_limits'] == 1 ? varset($pm_info['inbox']['total'],'') : varset($pm_info['inbox']['size'],''));
				if(!$pm_info['inbox']['limit'] || !$pm_info['inbox']['limit_val'])
				{
					$pm_info['inbox']['filled'] = 0;
				}
				else
				{
					$pm_info['inbox']['filled'] = number_format($pm_info['inbox']['limit_val']/$pm_info['inbox']['limit'] * 100, 2, '.', '');
				}
				$pm_info['outbox']['limit_val'] = ($this->pmPrefs['pm_limits'] == 1 ? varset($pm_info['outbox']['total'],'') : varset($pm_info['outbox']['size'],''));
				if(!$pm_info['outbox']['limit'] || !$pm_info['outbox']['limit_val'])
				{
					$pm_info['outbox']['filled'] = 0;
				}
				else
				{
					$pm_info['outbox']['filled'] = number_format($pm_info['outbox']['limit_val']/$pm_info['outbox']['limit'] * 100, 2, '.', '');
				}
			}
			else
			{
				if (!isset($pm_info['inbox']) || !is_array($pm_info['inbox'])) $pm_info['inbox'] = [];
				if (!isset($pm_info['outbox']) || !is_array($pm_info['outbox'])) $pm_info['outbox'] = [];

				$pm_info['inbox']['limit'] = '';
				$pm_info['outbox']['limit'] = '';
				$pm_info['inbox']['filled'] = '';
				$pm_info['outbox']['filled'] = '';
			}
		}
		return $pm_info;
	}

}

// Backward compat. fix.
function pm_getInfo($which = 'inbox')
{
	$pm = new pmbox_manager;

	return $pm->pm_getInfo($which);

}

/**
 * @param string $action pm.php action segment, e.g. "del" or "block"
 * @return bool whether the action changes state and so must present an e-token
 */
function pm_actionNeedsToken($action)
{
	return in_array($action, array('block', 'del', 'delblocked', 'unblock', 'deletedraft'), true);
}


/**
 * @return bool whether SEO-friendly (SEF) urls are active for the PM plugin.
 *              Mirrors the e107::url() check: mod_rewrite (forced in CLI)
 *              plus the plugin being enabled in the eurl admin settings.
 */
function pm_sefActive()
{
	$rewrite = e107::isCli() ? true : deftrue('e_MOD_REWRITE');
	return $rewrite && !empty(e107::getPref('e_url_list')['pm']);
}

/**
 * Base prefix for relative PM action links: with SEF on the clean path
 * (e.g. "https://site/pm/"), otherwise the legacy "pm.php?" form.
 * @return string
 */
function pm_url_prefix()
{
	if (pm_sefActive())
	{
		return e107::url('pm', 'index', array('route' => ''));
	}
	return e107::url('pm', 'index') . '?';
}

/**
 * Build a PM url. $route is the pm.php route segment (e.g. 'show.12.inbox'):
 * with SEF on it becomes part of the path, otherwise the legacy query string.
 * @param string $route route segment ('' for the inbox root)
 * @param array  $query extra query-string key/values (url-encoded)
 * @param string $mode  'abs' (default) or 'full' (absolute, for e-mail links)
 * @return string
 */
function pm_url($route = '', $query = array(), $mode = 'abs')
{
	$route = ltrim((string) $route, '/');
	if (pm_sefActive())
	{
		$url = e107::url('pm', 'index', array('route' => $route), array('mode' => $mode));
	}
	else
	{
		$url = rtrim(e107::url('pm', 'index', null, array('mode' => $mode)), '?');
		if ($route !== '')
		{
			$url .= '?' . $route;
		}
	}
	if (!empty($query))
	{
		$url .= (strpos($url, '?') === false ? '?' : '&') . http_build_query($query);
	}
	return $url;
}

/**
 * @param string $url pm.php url naming an action - legacy ("pm.php?del.12.inbox")
 *                    or SEF ("/pm/del.12.inbox") form
 * @param string $sep query-string separator, '&amp;' where the result lands in markup
 * @return string the url, carrying an e-token where the action it names changes state
 */
function pm_actionUrl($url, $sep)
{
	// The action lives in the query string (legacy) or in the last path
	// segment (SEF), so read whichever is present.
	if (($qpos = strpos($url, '?')) !== false)
	{
		$tail = substr($url, $qpos + 1);
	}
	else
	{
		$slash = strrpos($url, '/');
		$tail  = ($slash !== false) ? substr($url, $slash + 1) : $url;
	}
	$action = strtok($tail, '.&');
	if (!pm_actionNeedsToken((string) $action))
	{
		return $url;
	}
	$glue = (strpos($url, '?') === false) ? '?' : $sep;
	return $url . $glue . 'e-token=' . defset('e_TOKEN');
}

/**
 * Return an inline SVG icon (stroke-based, currentColor, 1em square).
 * Consistent across themes — no Font Awesome / PNG dependency.
 *
 * @param string $name  icon key
 * @return string  SVG markup
 */
function pm_icon($name)
{
    $s = 'width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-0.125em"';
    switch ($name) {
        case 'inbox':
            return "<svg {$s}><rect x=\"2\" y=\"4\" width=\"20\" height=\"16\" rx=\"2\"/><path d=\"M22 7l-10 7L2 7\"/></svg>";
        case 'outbox':
            return "<svg {$s}><line x1=\"22\" y1=\"2\" x2=\"11\" y2=\"13\"/><polygon points=\"22 2 15 22 11 13 2 9 22 2\"/></svg>";
        case 'drafts':
        case 'edit':
            return "<svg {$s}><path d=\"M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z\"/></svg>";
        case 'blocked':
        case 'ban':
            return "<svg {$s}><circle cx=\"12\" cy=\"12\" r=\"10\"/><line x1=\"4.93\" y1=\"4.93\" x2=\"19.07\" y2=\"19.07\"/></svg>";
        case 'attach':
            return "<svg {$s}><path d=\"M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48\"/></svg>";
        case 'delete':
        case 'trash':
            return "<svg {$s}><polyline points=\"3 6 5 6 21 6\"/><path d=\"M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2\"/></svg>";
        case 'block':
            return "<svg {$s}><path d=\"M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2\"/><circle cx=\"8.5\" cy=\"7\" r=\"4\"/><line x1=\"18\" y1=\"8\" x2=\"23\" y2=\"13\"/><line x1=\"23\" y1=\"8\" x2=\"18\" y2=\"13\"/></svg>";
        case 'unblock':
            return "<svg {$s}><path d=\"M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2\"/><circle cx=\"8.5\" cy=\"7\" r=\"4\"/><line x1=\"20\" y1=\"8\" x2=\"20\" y2=\"14\"/><line x1=\"23\" y1=\"11\" x2=\"17\" y2=\"11\"/></svg>";
        case 'check':
            return "<svg {$s}><polyline points=\"20 6 9 17 4 12\"/></svg>";
        case 'dot':
            return "<svg width=\"1em\" height=\"1em\" viewBox=\"0 0 24 24\" fill=\"currentColor\" style=\"vertical-align:-0.125em\"><circle cx=\"12\" cy=\"12\" r=\"4\"/></svg>";
        default:
            return '';
    }
}
