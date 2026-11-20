<?php
/**
 * e107 website system
 *
 * Copyright (C) 2008-2016 e107 Inc (e107.org)
 * Released under the terms and conditions of the
 * GNU General Public License (http://www.gnu.org/licenses/gpl.txt)
 *
*/



if (!defined('e107_INIT')) { exit; }

class pm_shortcodes extends e_shortcode
{

	private $pm = null;
	private $prefs = null;




	function __construct()
	{
		e107::includeLan(e_PLUGIN.'pm/languages/'.e_LANGUAGE.'/'.e_LANGUAGE.'.php');
		require_once(e_PLUGIN."pm/pm_func.php");

		$this->pm = new pmbox_manager();

		$this->prefs = $this->pm->prefs();

	}



	function sc_pm_nav($parm='')
	{
		$tp = e107::getParser();
		if(!isset($this->prefs['pm_class']) || !check_class($this->prefs['pm_class']))
		{
			return null;
		}
		$mbox = $this->pm->pm_getInfo('inbox');

		if(!empty($mbox['inbox']['new']))
		{
			$count = "<span class='label label-warning'>".$mbox['inbox']['new']."</span>";
			$icon  = pm_icon('inbox');
			$aria  = LAN_PLUGIN_PM_NAV.', '.intval($mbox['inbox']['new']).' ' . LAN_PLUGIN_PM_UNREAD;
		}
		else
		{
			$icon  = pm_icon('inbox');
			$count = '';
			$aria  = LAN_PLUGIN_PM_NAV;
		}

		$aria = $tp->toAttribute($aria);

		$urlInbox   = pm_url('inbox');
		$urlOutbox  = pm_url('outbox');
		$urlCompose = pm_url('send');
		return '<a class="pm-nav nav-link dropdown-toggle" data-toggle="dropdown" data-bs-toggle="dropdown" href="#" role="button" aria-expanded="false" aria-label="'.$aria.'">'.$icon.$count.'</a>
		<ul class="dropdown-menu dropdown-menu-right dropdown-menu-end">
			<li><a class="dropdown-item" href="'.$urlInbox.'">'.LAN_PLUGIN_PM_INBOX.'</a></li>
			<li><a class="dropdown-item" href="'.$urlOutbox.'">'.LAN_PLUGIN_PM_OUTBOX.'</a></li>
			<li><a class="dropdown-item" href="'.$urlCompose.'">'.LAN_PLUGIN_PM_NEW.'</a></li> 
		</ul>';
	}


	/**
	 * @param array|string $parm - User ID or array of values (see below)
	 * @param int $parm['user']
	 * @param string $parm['glyph']
	 * @param string $parm['class']
	 *
	 * @return null|string
	 */
	function sc_sendpm($parm=null)
	{

		// global $sysprefs, $pm_prefs;
		// $pm_prefs = $sysprefs->getArray("pm_prefs");

		if(is_string($parm))
		{
			$parm = array('user'=>$parm);
		}
		
		$pm_prefs = e107::getPlugPref('pm');

		$url = pm_url('send.'.varset($parm['user']));

		require_once(e_PLUGIN."pm/pm_class.php");

		$pm = new private_message;

		$glyph  = empty($parm['glyph']) ? 'outbox' : $parm['glyph'];
		$class  = empty($parm['class']) ? 'sendpm btn btn-sm btn-default btn-secondary' : $parm['class'];


		if(isset($pm_prefs['pm_class']) && check_class($pm_prefs['pm_class']) && $pm->canSendTo($parm['user'])) // check $this->pmPrefs['send_to_class'].
		{
		    if(deftrue('FONTAWESOME') && deftrue('BOOTSTRAP'))
		    {
		        $img =  e107::getParser()->toGlyph($glyph,'');
		        return  "<a class='".$class."' href='".$url ."'>{$img} ".LAN_PLUGIN_PM_NEW."</a>";
		    }


		    if(file_exists(THEME.'forum/pm.png'))
		    {
		           $img = "<img src='".THEME_ABS."forum/pm.png' alt='".LAN_PM."' title='".LAN_PM."' style='border:0' />";
		     }
		     else
		     {
		          $img = "<img src='".e_PLUGIN_ABS."pm/images/pm.png' alt='".LAN_PM."' title='".LAN_PM."' style='border:0' />";
		     }



			return  "<a class='sendpm' href='".$url ."'>{$img}</a>";
		}
		else
		{
			return null;
		}




	}



}