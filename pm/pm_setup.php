<?php
/*
* e107 website system
*
* Copyright (C) 2008-2009 e107 Inc (e107.org)
* Released under the terms and conditions of the
* GNU General Public License (http://www.gnu.org/licenses/gpl.txt)
*
*	PM plugin - install/uninstall routines
*
* $Source: /cvs_backup/e107_0.8/e107_plugins/pm/pm_setup.php,v $
* $Revision$
* $Date$
* $Author$
*
*/

/**
 *	e107 Private messenger plugin
 *
 *	install/uninstall routines
 *
 *	@package	e107_plugins
 *	@subpackage	pm
 *	@version	$Id$;
 */

use e107\Database\SqlFragment;

class pm_setup
{

	/**
	 *	Cover the attachment directories this site already holds.
	 *
	 *	A site whose attachments were stored before the deny rules existed has
	 *	this and nothing else: a send covers the sender's own directory and the
	 *	root above it and reads no other, and the members whose files are sitting
	 *	there exposed are the ones who are not sending any. The plugin's version is
	 *	raised in plugin.xml
	 *	so that this runs on every existing site rather than only on a fresh
	 *	install.
	 *
	 *	@return	void
	 */
	function install_post()
	{
		require_once(e_PLUGIN . 'pm/pm_class.php');

		$pm = new private_message();

		// Ensure the encryption key + its protected folder exist.
		$pm->cryptoEnsure();

		// The drafts table is created here (and on upgrade) rather than in
		// pm_sql.php alone, so sites that installed before it existed pick it
		// up on the next upgrade. IF NOT EXISTS keeps it idempotent.
		$this->createDraftTable();

		if(!$pm->protectStoredAttachments())
		{
			e107::lan('pm', null);
			e107::getMessage()->addWarning(defset('LAN_PM_116'));
		}
	}


	function upgrade_post()
	{
		$this->install_post();

		// Encrypt any messages stored before encryption was enabled.
		$pm = new private_message();
		$pm->migrateEncryptMessages();
	}


	/**
	 *	Create the private_msg_draft table if it does not exist yet.
	 *	Runs on MySQL and MariaDB alike (IF NOT EXISTS is supported by both).
	 *
	 *	@return void
	 */
	function createDraftTable()
	{
		$sql = e107::getDb();

		// Idempotent: isTable() takes the logical name (prefix applied by e107).
		// createTableRaw() owns the CREATE TABLE envelope and the table prefix.
		if($sql->isTable('private_msg_draft'))
		{
			return;
		}

		$sql->schema()->createTableRaw('private_msg_draft', SqlFragment::raw("
			`pm_draft_id` int(10) unsigned NOT NULL auto_increment,
			`pm_draft_uid` int(10) unsigned NOT NULL default '0',
			`pm_to` varchar(250) NOT NULL default '',
			`pm_subject` text NOT NULL,
			`pm_text` mediumtext NOT NULL,
			`pm_option` varchar(250) NOT NULL default '',
			`pm_size` int(10) unsigned NOT NULL default '0',
			`pm_created` int(10) unsigned NOT NULL default '0',
			`pm_modified` int(10) unsigned NOT NULL default '0',
			PRIMARY KEY (`pm_draft_id`),
			KEY `pm_draft_uid` (`pm_draft_uid`)
		"), array('engine' => 'MyISAM'));
	}


	function uninstall_post()
	{
		$sql = e107::getDb();
		$sql->createQueryBuilder()->delete('core')->where('e107_name', 'pm_prefs')->execute();
		$sql->createQueryBuilder()->delete('menus')->where('menu_name', 'private_msg_menu')->execute();
	}
	
}
