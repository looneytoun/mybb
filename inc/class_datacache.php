<?php
/**
 * MyBB 1.2
 * Copyright � 2006 MyBB Group, All Rights Reserved
 *
 * Website: http://www.mybboard.net
 * License: http://www.mybboard.net/eula.html
 *
 * $Id$
 */

class datacache
{
	/**
	 * Cache contents.
	 *
	 * @var array
	 */
	var $cache = array();

	/**
	 * Build cache data.
	 *
	 */
	function cache()
	{
		global $db, $mybb;
		if($mybb->config['cache_store'] == "files")
		{
			// Check if no files exist in cache directory, if not we need to create them (possible move from db to files)
			if(!file_exists(MYBB_ROOT."inc/cache/version.php"))
			{
				$query = $db->simple_select(TABLE_PREFIX."datacache", "title,cache");
				while($data = $db->fetch_array($query))
				{
					$this->update($data['title'], unserialize($data['cache']));
				}
			}
		}
		else
		{
			$query = $db->simple_select(TABLE_PREFIX."datacache", "title,cache");
			while($data = $db->fetch_array($query))
			{
				$this->cache[$data['title']] = unserialize($data['cache']);
			}
		}
	}

	/**
	 * Read cache from files or db.
	 *
	 * @param string The cache component to read.
	 * @param boolean If true, cannot be overwritten during script execution.
	 * @return unknown
	 */
	function read($name, $hard=false)
	{
		global $db, $mybb;
		if($mybb->config['cache_store'] == "files")
		{
			if(!isset($this->cache[$name]) || $hard)
			{
				@include(MYBB_ROOT."inc/cache/".$name.".php");
				$this->cache[$name] = $$name;
			}
		}
		else
		{
			if($hard)
			{
				$query = $db->simple_select(TABLE_PREFIX."datacache", "title,cache", "title='$name'");
				$data = $db->fetch_array($query);
				$this->cache[$data['title']] = unserialize($data['cache']);
			}
		}
		
		if(isset($this->cache[$name]))
		{
			return $this->cache[$name];
		}
		return false;
	}

	/**
	 * Update cache contents.
	 *
	 * @param string The cache content identifier.
	 * @param string The cache content.
	 */
	function update($name, $contents)
	{
		global $db, $mybb;
		$this->cache[$name] = $contents;

		// We ALWAYS keep a running copy in the db just incase we need it
		$dbcontents = $db->escape_string(serialize($contents));
		$db->query("
			REPLACE INTO ".TABLE_PREFIX."datacache (title, cache)
			VALUES ('$name','$dbcontents')
		");

		// If using files, update the cache file too
		if($mybb->config['cache_store'] == "files")
		{
			if(!@is_writable(MYBB_ROOT."inc/cache/"))
			{
				$mybb->trigger_generic_error("cache_no_write");
			}
			$cachefile = fopen(MYBB_ROOT."inc/cache/$name.php", "w");
			$cachecontents = "<?php\n\n/** MyBB Generated Cache - Do Not Alter\n * Cache Name: $name\n * Generated: ".gmdate("r")."\n*/\n\n";
			$cachecontents .= "\$$name = ".var_export($contents, true).";\n\n ?>";
			fwrite($cachefile, $cachecontents);
			fclose($cachefile);
		}
	}

	/**
	 * Update the MyBB version in the cache.
	 *
	 */
	function updateversion()
	{
		global $db, $mybb;
		$version = array(
			"version" => $mybb->version,
			"version_code" => $mybb->version_code
		);
		$this->update("version", $version);
	}

	/**
	 * Update the attachment type cache.
	 *
	 */
	function updateattachtypes()
	{
		global $db;
		$query = $db->simple_select(TABLE_PREFIX."attachtypes", "atid, name, mimetype, extension, maxsize, icon");
		while($type = $db->fetch_array($query))
		{
			$type['extension'] = strtolower($type['extension']);
			$types[$type['extension']] = $type;
		}
		$this->update("attachtypes", $types);
	}

	/**
	 * Update the smilies cache.
	 *
	 */
	function updatesmilies()
	{
		global $db;
		$query = $db->simple_select(TABLE_PREFIX."smilies", "sid, name, find, image, disporder, showclickable", "", array('order_by' => 'LENGTH(find)', 'order_dir' => 'DESC'));
		while($smilie = $db->fetch_array($query))
		{
			$smilies[$smilie['sid']] = $smilie;
		}
		$this->update("smilies", $smilies);
	}

	/**
	 * Update the posticon cache.
	 *
	 */
	function updateposticons()
	{
		global $db;
		$query = $db->simple_select(TABLE_PREFIX."icons", "iid, name, path");
		while($icon = $db->fetch_array($query))
		{
			$icons[$icon['iid']] = $icon;
		}
		$this->update("posticons", $icons);
	}

	/**
	 * Update the badwords cache.
	 *
	 */
	function updatebadwords()
	{
		global $db;
		$query = $db->simple_select(TABLE_PREFIX."badwords", "bid, badword, replacement");
		while($badword = $db->fetch_array($query)) 
		{
			$badwords[$badword['bid']] = $badword;
		}
		$this->update("badwords", $badwords);
	}

	/**
	 * Update the usergroups cache.
	 *
	 */
	function updateusergroups()
	{
		global $db;
		$query = $db->simple_select(TABLE_PREFIX."usergroups");
		while($g = $db->fetch_array($query))
		{
			$gs[$g['gid']] = $g;
		}
		$this->update("usergroups", $gs);
	}

	/**
	 * Update the forum permissions cache.
	 *
	 * @return false When failed, returns false.
	 */
	function updateforumpermissions()
	{
		global $forum_cache, $fcache, $db, $usergroupcache, $fperms, $fpermfields, $forumpermissions;

		// Get usergroups
		$query = $db->simple_select(TABLE_PREFIX."usergroups");
		while($usergroup = $db->fetch_array($query))
		{
			$gid = $usergroup['gid'];
			foreach($usergroup as $key => $val)
			{
				if(!in_array($key, $fpermfields))
				{
					unset($usergroup[$key]);
				}
			}
			$usergroupcache[$gid] = $usergroup;
		}
	
		// Get our forum list
		cache_forums(true);
		$fcache = array();
		if(!is_array($forum_cache))
		{
			return false;
		}
		reset($forum_cache);
		foreach($forum_cache as $fid => $forum)
		{
			$fcache[$forum['pid']][$forum['disporder']][$forum['fid']] = $forum;
		}
		foreach($fcache as $pid => $value)
		{
			ksort($fcache[$pid]);
		}
		ksort($fcache);
	
		// Fetch forum permissions
		$query = $db->simple_select(TABLE_PREFIX."forumpermissions");
		while($fperm = $db->fetch_array($query))
		{
			$fperms[$fperm['fid']][$fperm['gid']] = $fperm;
		}
		$this->buildforumpermissions();
		$this->update("forumpermissions", $forumpermissions);
	}

	/**
	 * Build the forumpermissions cache.
	 *
	 * @param array An optional permissions array.
	 * @param int An optional permission id.
	 */
	function buildforumpermissions($permissions="", $pid=0)
	{
		global $fcache, $usergroupcache, $fperms, $forumpermissions;
		if($fcache[$pid])
		{
			foreach($fcache[$pid] as $key => $main)
			{
				foreach($main as $forum)
				{
					$perms = $permissions;
					foreach($usergroupcache as $gid => $usergroup)
					{
						if($fperms[$forum['fid']][$gid])
						{
							$perms[$gid] = $fperms[$forum['fid']][$gid];
						}
						if($perms[$gid])
						{
							$forumpermissions[$forum['fid']][$gid] = $perms[$gid];
						}
					}
					$this->buildforumpermissions($perms, $forum['fid']);
				}
			}
		}
	}

	/**
	 * Update the stats cache (kept for the sake of being able to rebuild this cache via the cache interface)
	 *
	 */
	function updatestats()
	{
		global $db;
		
		require_once MYBB_ROOT."inc/functions_rebuild.php"; 
 		rebuild_stats(); 
	}

	function updatemoderators()
	{
		global $forum_cache, $db;

		// Get our forum list
		cache_forums(true);
		if(!is_array($forum_cache))
		{
			return false;
		}
		
		reset($forum_cache);
		$fcache = array();
		
		// Resort in to the structure we require
		foreach($forum_cache as $fid => $forum)
		{
			$this->moderators_forum_cache[$forum['pid']][$forum['disporder']][$forum['fid']] = $forum;
		}
		
		// Sort children
		foreach($fcache as $pid => $value)
		{
			ksort($fcache[$pid]);
		}
		ksort($fcache);
	
		// Fetch moderators from the database
		$query = $db->simple_select(TABLE_PREFIX."moderators");
		while($moderator = $db->fetch_array($query))
		{
			$this->moderators[$moderator['fid']][$moderator['uid']] = $moderator;
		}

		$this->build_moderators();
		
		$this->update("moderators", $this->built_moderators);
		
		// The second moderator cache used in forumdisplay.php
		$query = $db->query("
			SELECT m.uid, m.fid, u.username, u.usergroup, u.displaygroup
			FROM ".TABLE_PREFIX."moderators m
			LEFT JOIN ".TABLE_PREFIX."users u ON (m.uid=u.uid)
			ORDER BY u.username
		");
		// Build a moderator cache.
		while($moderator = $db->fetch_array($query))
		{
			$moderatorcache[$moderator['fid']][$moderator['uid']] = $moderator;
		}
		
		$this->update("moderators_2", $moderatorcache);
	}

	/**
	 * Build the moderators array
	 *
	 * @access private
	 * @param array An optional moderators array (moderators of the parent forum for example).
	 * @param int An optional parent ID.
	 */
	function build_moderators($moderators=array(), $pid=0)
	{
		if($this->moderators_forum_cache[$pid])
		{
			foreach($this->moderators_forum_cache[$pid] as $main)
			{
				foreach($main as $forum)
				{
					$forum_mods = '';
					if($moderators)
					{
						$forum_mods = $moderators;
					}
					// Append - local settings override that of a parent - array_merge works here
					if($this->moderators[$forum['fid']])
					{
						if(is_array($forum_mods))
						{
							$forum_mods = array_merge($forum_mods, $this->moderators[$forum['fid']]);
						}
						else
						{
							$forum_mods = $this->moderators[$forum['fid']];
						}
					}
					$this->built_moderators[$forum['fid']] = $forum_mods;
					$this->build_moderators($forum_mods, $forum['fid']);
				}
			}
		}
	}
	
	/**
	 * Update the second moderators cache.
	 *
	 */
	function updatemoderators_2()
	{
		global $db;
		
		// The second moderator cache used in forumdisplay.php
		$query = $db->query("
			SELECT m.uid, m.fid, u.username, u.usergroup, u.displaygroup
			FROM ".TABLE_PREFIX."moderators m
			LEFT JOIN ".TABLE_PREFIX."users u ON (m.uid=u.uid)
			ORDER BY u.username
		");
		// Build a moderator cache.
		while($moderator = $db->fetch_array($query))
		{
			$moderatorcache[$moderator['fid']][$moderator['uid']] = $moderator;
		}
		
		$this->update("moderators_2", $moderatorcache);
	}

	/**
	 * Update the forums cache.
	 *
	 */
	function updateforums()
	{
		global $db;
		$exclude = array("threads", "posts", "lastpost", "lastposter", "lastposttid");
		$query = $db->simple_select(TABLE_PREFIX."forums", "*", "", array('order_by' => 'pid,disporder'));

		while($forum = $db->fetch_array($query))
		{
			foreach($forum as $key => $val)
			{
				if(in_array($key, $exclude))
				{
					unset($forum[$key]);
				}
				$forums[$forum['fid']] = $forum;
			}
		}
		$this->update("forums", $forums);
		
		$forumjump = build_forum_jump("");
		
		$this->update("forumjump", $forumjump);
	}
	
	/**
	 * Update the forum jump cache.
	 *
	 */
	function updateforumjump()
	{
		$forumjump = build_forum_jump("");
		
		$this->update("forumjump", $forumjump);
	}

	/**
	 * Update usertitles cache.
	 *
	 */
	function updateusertitles()
	{
		global $db;
		$query = $db->simple_select(TABLE_PREFIX."usertitles", "utid, posts, title, stars, starimage", "", array('order_by' => 'posts', 'order_dir' => 'DESC'));
		
		while($usertitle = $db->fetch_array($query))
		{
			$usertitles[] = $usertitle;
		}
		$this->update("usertitles", $usertitles);
	}

	/**
	 * Update reported posts cache.
	 *
	 */
	function updatereportedposts()
	{
		global $db;
		$query = $db->simple_select(TABLE_PREFIX."reportedposts", "COUNT(rid) AS unreadcount", "reportstatus='0'");
		$num = $db->fetch_array($query);
		$query = $db->simple_select(TABLE_PREFIX."reportedposts", "COUNT(rid) AS reportcount");
		$total = $db->fetch_array($query);
		$query = $db->simple_select(TABLE_PREFIX."reportedposts", "dateline", "reportstatus='0'", array('order_by' => 'dateline', 'order_dir' => 'DESC'));
		$latest = $db->fetch_array($query);
		$reports['unread'] = $num['unreadcount'];
		$reports['total'] = $total['reportcount'];
		$reports['lastdateline'] = $latest['dateline'];
		$this->update("reportedposts", $reports);
	}

	/**
	 * Update mycode cache.
	 *
	 */
	function updatemycode()
	{
		global $db;
		$query = $db->simple_select(TABLE_PREFIX."mycode", "regex, replacement", "active='yes'");
		while($mycode = $db->fetch_array($query))
		{
			$mycodes[] = $mycode;
		}
		$this->update("mycode", $mycodes);
	}
	/**
	 * Update the mailqueue cache
	 *
	 */
	function updatemailqueue($last_run=0, $lock_time=0)
	{
		global $db;
		$query = $db->simple_select(TABLE_PREFIX."mailqueue", "COUNT(*) AS queue_size");
		$queue_size = $db->fetch_field($query, "queue_size");
		
		$mailqueue = $this->read("mailqueue");
		$mailqueue['queue_size'] = $queue_size;
		if($last_run > 0)
		{
			$mailqueue['last_run'] = $last_run;
		}
		$mailqueue['locked'] = $lock_time;
		$this->update("mailqueue", $mailqueue);
	}
	
	/**
	 * Update update_check cache (dummy function used by upgrade/install scripts)
	 */
	function updateupdate_check()
	{
		$update_cache = array(
			"dateline" => time()
		);
		$this->update("update_check", $update_cache);
	}
}
?>