<?php
/*
 * e107 website system
 *
 * Copyright (C) 2008-2009 e107 Inc (e107.org)
 * Released under the terms and conditions of the
 * GNU General Public License (http://www.gnu.org/licenses/gpl.txt)
 *
 *	PM plugin - menu display (Bootstrap 5.3)
 *
 */

if (!defined('e107_INIT')) { exit; }
if (!e107::isInstalled('pm')) { return ''; }

$pm_prefs = e107::getPlugPref('pm');
$pmClass = varset($pm_prefs['pm_class'], e_UC_NOBODY);
if(!check_class($pmClass))
{
	return null;
}


/**
 *	Function to show a popup (if enabled) when new PMs arrive.
 *
 *	@param	array	$pm_inbox - information about current state of inbox
 *	@param	int		$alertdelay - delay between popups, in seconds
 *
 *	@return string - text for display
 */
if(!function_exists('pm_show_popup'))
{
    function pm_show_popup($pm_inbox, $alertdelay = 0)
    {
        if($alertdelay == 0) { $alertdelay = 60; }
        eShims::setcookie('pm-alert', 'ON', time()+$alertdelay);
        $popuptext = "
	<html>
		<head>
			<title>".$pm_inbox['inbox']['new'].' '.LAN_PM_109."</title>
		</head>
		<body style='padding:4px;margin:0;text-align:center;font-family:sans-serif'>
			<b>".LAN_PM."</b><br />
			".$pm_inbox['inbox']['new'].' '.LAN_PM_109."<br />
			".$pm_inbox['inbox']['unread'].' '.LAN_PM_37."<br /><br />
			<button type='button' onclick='self.close();' style='padding:2px 12px;cursor:pointer'>".LAN_OK."</button>
		</body>
	</html> ";
        $popuptext = str_replace("\n", '', $popuptext);
        $popuptext = str_replace("\t", '', $popuptext);
        $text = "
	<script>
	(function(){
		winl=(screen.width-200)/2;
		wint=(screen.height-100)/2;
		winProp='width=200,height=100,left='+winl+',top='+wint+',scrollbars=no';
		var w=window.open('about:blank','pm_popup',winProp);
		w.document.write(\"".$popuptext."\");
		w.document.close();
	})();
	</script>";
        return $text;
    }
}

require_once(e_PLUGIN.'pm/pm_func.php');
e107::getScParser();
require_once(e_PLUGIN.'pm/pm_shortcodes.php');

$pmManager = new pmbox_manager($pm_prefs);
$template  = e107::getTemplate('pm', 'pm_menu');

if(!isset($template))
{
	// Fallback (BS5.3) – used when pm_menu_template.php is missing
	$inboxUrl  = pm_url('inbox');
	$outboxUrl = pm_url('outbox');
	$blockedUrl= pm_url('blocked');

	$template = "
<nav class='pm-menu' aria-label='".LAN_PM."'>
  <ul class='list-unstyled mb-0 small'>
    <li class='d-flex align-items-center py-1'>
      <a href='".$inboxUrl."' class='d-flex align-items-center text-decoration-none flex-grow-1'>
        <span class='me-2'>".PM_INBOX_ICON."</span>
        <span>".LAN_PLUGIN_PM_INBOX."</span>
        <span class='ms-auto'>{PM_NEWPM_ANIMATE}</span>
      </a>
    </li>
    <li class='px-3 py-1 text-muted'>{PM_INBOX_TOTAL} ".LAN_PM_36.", {PM_INBOX_UNREAD} ".LAN_PM_37." {PM_INBOX_FILLED}</li>
    <li class='d-flex align-items-center py-1 mt-1'>
      <a href='".$outboxUrl."' class='d-flex align-items-center text-decoration-none flex-grow-1'>
        <span class='me-2'>".PM_OUTBOX_ICON."</span>
        <span>".LAN_PLUGIN_PM_OUTBOX."</span>
      </a>
    </li>
    <li class='px-3 py-1 text-muted'>{PM_OUTBOX_TOTAL} ".LAN_PM_36.", {PM_OUTBOX_UNREAD} ".LAN_PM_37." {PM_OUTBOX_FILLED}</li>
  </ul>
  {PM_SEND_PM_LINK}
  {PM_BLOCKED_SENDERS_MANAGE}
</nav>";
}

$tp = e107::getParser();
$sc = e107::getScBatch('pm', TRUE, 'pm');

$pm_inbox = $pmManager->pm_getInfo('inbox');
$sc->wrapper('pm_menu');

$txt = "\n".$tp->parseTemplate($template, TRUE, $sc);

$inboxNew = (int) varset($pm_inbox['inbox']['new'], 0);
$pmSelfBase = rtrim(e107::url('pm', 'index', array('route' => ''), array('mode' => 'raw')), '?');
$onPmPage   = (strpos(e_SELF, 'pm.php') !== FALSE || strpos(e_SELF, $pmSelfBase) !== FALSE);
if($inboxNew > 0 && $pm_prefs['popup'] && !$onPmPage && $_COOKIE['pm-alert'] != 'ON')
{
	$txt .= pm_show_popup($pm_inbox, $pm_prefs['popup_delay']);
}

$caption = varset($pm_prefs['title'], LAN_PM);
$caption = defset($caption, $caption);
$ns->tablerender($caption, $txt, 'pm');
