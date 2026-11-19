CREATE TABLE private_msg (
  pm_id int(10) unsigned NOT NULL auto_increment,
  pm_from int(10) unsigned NOT NULL default '0',
  pm_to varchar(250) NOT NULL default '',
  pm_sent int(10) unsigned NOT NULL default '0',			/* Date sent */
  pm_read int(10) unsigned NOT NULL default '0',			/* Date read */
  pm_subject text NOT NULL,
  pm_text mediumtext NOT NULL,
  pm_sent_del tinyint(1) unsigned NOT NULL default '0',	/* Set when can delete */
  pm_read_del tinyint(1) unsigned NOT NULL default '0',	/* set when can delete */
  pm_attachments text NOT NULL,
  pm_option varchar(250) NOT NULL default '',			/* Options associated with PM - '+rr' for read receipt */
  pm_size int(10) unsigned NOT NULL default '0',
  PRIMARY KEY  (pm_id),
  KEY pm_from (pm_from)
) ENGINE=MyISAM AUTO_INCREMENT=1 ;

CREATE TABLE private_msg_block (
  pm_block_id int(10) unsigned NOT NULL auto_increment,
  pm_block_from int(10) unsigned NOT NULL default '0',
  pm_block_to int(10) unsigned NOT NULL default '0',
  pm_block_datestamp int(10) unsigned NOT NULL default '0',
  pm_block_count int(10) unsigned NOT NULL default '0',	/* Counts number of blocked PMs */
  PRIMARY KEY  (pm_block_id)
) ENGINE=MyISAM AUTO_INCREMENT=1 ;

/*
 * Drafts folder (D4). Subject and body are stored encrypted, exactly like
 * private_msg (see pm_crypto.php). pm_size is the plaintext byte length so the
 * quota display stays consistent. Only the owner (pm_draft_uid) may read it.
 */
CREATE TABLE private_msg_draft (
  pm_draft_id int(10) unsigned NOT NULL auto_increment,
  pm_draft_uid int(10) unsigned NOT NULL default '0',		/* Owner of the draft */
  pm_to varchar(250) NOT NULL default '',				/* Recipient id(s) / class / name list */
  pm_subject text NOT NULL,
  pm_text mediumtext NOT NULL,
  pm_option varchar(250) NOT NULL default '',
  pm_size int(10) unsigned NOT NULL default '0',
  pm_created int(10) unsigned NOT NULL default '0',
  pm_modified int(10) unsigned NOT NULL default '0',
  PRIMARY KEY  (pm_draft_id),
  KEY pm_draft_uid (pm_draft_uid)
) ENGINE=MyISAM AUTO_INCREMENT=1 ;
