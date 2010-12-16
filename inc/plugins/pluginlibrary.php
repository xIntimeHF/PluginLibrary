<?php
/**
 * This file is part of PluginLibrary for MyBB.
 * Copyright (C) 2010 Andreas Klauer <Andreas.Klauer@metamorpher.de>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */

// Disallow direct access to this file for security reasons
if(!defined("IN_MYBB"))
{
    die("Direct initialization of this file is not allowed.<br /><br />Please make sure IN_MYBB is defined.");
}

/* --- Plugin API: --- */

function pluginlibrary_info()
{
    return array(
        "name"          => "PluginLibrary",
        "description"   => "A collection of useful functions used by other plugins.",
        "website"       => "https://github.com/frostschutz/PluginLibrary",
        "author"        => "Andreas Klauer",
        "authorsite"    => "mailto:Andreas.Klauer@metamorpher.de",
        "version"       => "1",
        "guid"          => "",
        "compatibility" => "*"
        );
}

function pluginlibrary_is_installed()
{
    // Don't try this at home.
    return false;
}

function pluginlibrary_install()
{
    // Avoid unnecessary activation as a plugin with a friendly success message.
    flash_message("The selected plugin does not have to be activated.", 'success');
    admin_redirect("index.php?module=config-plugins");
}

function pluginlibrary_uninstall()
{
}

function pluginlibrary_activate()
{
}

function pluginlibrary_deactivate()
{
}

/* --- PluginLibrary class: --- */

class PluginLibrary
{
    /**
     * Version number.
     */
    public $version = 1;

    /* --- Setting groups and settings: --- */

    /**
     * Create and/or update setting group and settings.
     *
     * @param string Internal unique group name and setting prefix.
     * @param string Group title that will be shown to the admin.
     * @param string Group description that will show up in the group overview.
     * @param array The list of settings to be added to that group.
     * @param bool Generate language file. (Developer option, default false)
     */
    function settings($name, $title, $description, $list, $makelang=false)
    {
        global $db;

        /* Setting group: */

        if($makelang)
        {
            header("Content-Type: text/plain; charset=UTF-8");
            echo "<?php\n/**\n * Settings language file generated by PluginLibrary.\n *\n */\n\n";
            echo "\$l['setting_group_{$name}'] = \"".addcslashes($title, '\\"$')."\";\n";
            echo "\$l['setting_group_{$name}_desc'] = \"".addcslashes($description, '\\"$')."\";\n";
        }

        // Group array for inserts/updates.
        $group = array('name' => $db->escape_string($name),
                       'title' => $db->escape_string($title),
                       'description' => $db->escape_string($description));

        // Check if the group already exists.
        $query = $db->simple_select("settinggroups", "gid", "name='${group['name']}'");

        if($row = $db->fetch_array($query))
        {
            // We already have a group. Update title and description.
            $gid = $row['gid'];
            $db->update_query("settinggroups", $group, "gid='{$gid}'");
        }

        else
        {
            // We don't have a group. Create one with proper disporder.
            $query = $db->simple_select("settinggroups", "MAX(disporder) AS disporder");
            $row = $db->fetch_array($query);
            $group['disporder'] = $row['disporder'] + 1;
            $gid = $db->insert_query("settinggroups", $group);
        }

        /* Settings: */

        // Deprecate all the old entries.
        $db->update_query("settings",
                          array("description" => "PLUGINLIBRARYDELETEMARKER"),
                          "gid='$gid'");

        // Create and/or update settings.
        foreach($list as $key => $setting)
        {
            // Prefix all keys with group name.
            $key = "{$name}_{$key}";

            if($makelang)
            {
                echo "\$l['setting_{$key}'] = \"".addcslashes($setting['title'], '\\"$')."\";\n";
                echo "\$l['setting_{$key}_desc'] = \"".addcslashes($setting['description'], '\\"$')."\";\n";
            }

            // Escape input values.
            $vsetting = array_map(array($db, 'escape_string'), $setting);

            // Add missing default values.
            $disporder += 1;

            $setting = array_merge(
                array('optionscode' => 'yesno',
                      'value' => '0',
                      'disporder' => $disporder),
                $setting);

            $setting['name'] = $db->escape_string($key);
            $setting['gid'] = $gid;

            // Check if the setting already exists.
            $query = $db->simple_select('settings', 'sid',
                                        "gid='$gid' AND name='{$setting['name']}'");

            if($row = $db->fetch_array($query))
            {
                // It exists, update it, but keep value intact.
                unset($setting['value']);
                $db->update_query("settings", $setting, "sid='{$row['sid']}'");
            }

            else
            {
                // It doesn't exist, create it.
                $db->insert_query("settings", $setting);
            }
        }

        if($makelang)
        {
            echo "\n?>\n";
            exit;
        }

        // Delete deprecated entries.
        $db->delete_query("settings",
                          "gid='$gid' AND description='PLUGINLIBRARYDELETEMARKER'");

        // Rebuild the settings file.
        rebuild_settings();
    }

    /**
     * Delete setting groups and settings.
     *
     * @param string Internal unique group name.
     * @param bool Also delete groups starting with name_.
     */
    function delete_settings($name, $greedy=false)
    {
        global $db;

        $name = $db->escape_string($name);
        $where = "name='{$name}'";

        if($greedy)
        {
            $where .= " OR name LIKE '{$name}_%'";
        }

        // Query the setting groups.
        $query = $db->simple_select('settinggroups', 'gid', $where);

        // Delete the group and all its settings.
        while($gid = $db->fetch_field($query, 'gid'))
        {
            $db->delete_query('settinggroups', "gid='{$gid}'");
            $db->delete_query('settings', "gid='{$gid}'");
        }

        // Rebuild the settings file.
        rebuild_settings();
    }

    /* --- Cache: --- */

    /**
     * Delete cache.
     *
     * @param string Cache name or title.
     * @param bool Also delete caches starting with name_.
     */
    function delete_cache($name, $greedy=false)
    {
        global $db, $cache;

        $name = $db->escape_string($name);
        $where = "title='{$name}'";

        if($greedy)
        {
            $where .= "OR title LIKE '{$name}_%'";
        }

        // Handle specialized cache handlers.
        if(is_object($cache->handler))
        {
            $query = $db->simple_select("datacache", "title", $where);

            while($row = $db->fetch_array($query))
            {
                $cache->handler->delete($row['title']);
            }
        }

        // Delete database cache (always present).
        $db->delete_query("datacache", $where);
    }

    /* --- Corefile edits: --- */

    function _comment($comment, $code)
    {
        if(!strlen($code))
        {
            return "";
        }

        if(substr($code, -1) == "\n")
        {
            $code = substr($code, 0, -1);
        }

        $code = str_replace("\n", "\n{$comment}", "\n{$code}");

        return substr($code, 1)."\n";
    }

    function _uncomment($comment, $code)
    {
        if(!strlen($code))
        {
            return "";
        }

        $code = "\n{$code}";
        $code = str_replace("\n{$comment}", "\n", $code);

        return substr($code, 1);
    }

    function _zapcomment($comment, $code)
    {
        return preg_replace("#".preg_quote($comment, "#").".*\n?#m", "", $code);
    }

    function simple_core_edit($name, $file, $edit)
    {
        $args = func_get_args();
        array_shift($args); // $name
        array_shift($args); // $file

        // read the file
        $contents = file_get_contents(MYBB_ROOT.$file);
        $original = $contents;
        $inscmt = "/* + PL:{$name} + */ ";
        $delcmt = "/* - PL:{$name} - /* ";

        if(!$contents)
        {
            // Could not read the file.
            return false;
        }

        // match the edits
        $matches = array();
        $matchescount = 0;

        while($edit = array_shift($args))
        {
            // Initialize variables for this edit.
            $search = (array)$edit['search'];
            $reversesearch = array_reverse($search);
            $stop = 0;

            // Find the pattern matches.
            do
            {
                // forward search (determine smallest stop)
                foreach($search as $value)
                {
                    $stop = strpos($contents, $value, $stop);

                    if($stop === false || (string)$value === "")
                    {
                        break 2; /* exit foreach and do-while */
                    }

                    $stop += strlen($value);
                }

                /* Match is complete, but possibly larger than it needs to be. */

                // backward search (determine largest start)
                $start = $stop;

                foreach($reversesearch as $value)
                {
                    $start = strrpos($contents, $value, -strlen($contents)+$start);
                }

                /* Match is complete and smallest possible. */

                // Bump to line boundaries.
                $nl = strrpos($contents, "\n", -strlen($contents)+$start);
                $start = ($nl === false ? 0 : $nl + 1);

                $nl = strpos($contents, "\n", $stop-1);
                $stop = ($nl === false ? strlen($contents) : $nl);

                /* Add the match to the list. */
                if(isset($matches[$start]))
                {
                    return false;
                }

                $matches[$start] = array($stop, $edit);
            } while(1);

            // No matches? Bail out to prevent incomplete edits.
            if($matchescount == count($matches))
            {
                return false;
            }

            $matchescount = count($matches);
        } /* while $edit */

        // Apply the edits.
        $pos = 0;
        $text = array();

        foreach($matches as $start => $val)
        {
            $stop = $val[0];
            $edit = $val[1];

            // outside match
            $text[] = substr($contents, $pos, $start-$pos);

            // insert before
            $text[] = $this->_comment($inscmt, $edit['before']);

            // insert or replace match
            $match = substr($contents, $start, $stop-$start+1);
            $pos = $stop + 1;

            if(isset($edit['replace']))
            {
                $text[] = $this->_comment($delcmt, $match);

                if(!strlen($edit['after']))
                {
                    $text[] = $inscmt . "\n";
                }
            }

            else
            {
                $text[] = $match;

                // Special case: no newline at the end of the file.
                if($pos == strlen($contents)+1)
                {
                    $text[] = "\n";
                }
            }

            // insert after
            $text[] = $this->_comment($inscmt, $edit['after']);
        }

        if($pos < strlen($contents))
        {
            $text[] = substr($contents, $pos);
        }

        $contents = implode("", $text);

        if($original != $contents)
        {
            return $contents;
        }
    }
}

global $PL;
$PL = new PluginLibrary();

/* --- End of file. --- */
?>
