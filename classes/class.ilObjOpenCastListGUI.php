<?php
/*
	+-----------------------------------------------------------------------------+
	| ILIAS open source                                                           |
	+-----------------------------------------------------------------------------+
	| Copyright (c) 1998-2009 ILIAS open source, University of Cologne            |
	|                                                                             |
	| This program is free software; you can redistribute it and/or               |
	| modify it under the terms of the GNU General Public License                 |
	| as published by the Free Software Foundation; either version 2              |
	| of the License, or (at your option) any later version.                      |
	|                                                                             |
	| This program is distributed in the hope that it will be useful,             |
	| but WITHOUT ANY WARRANTY; without even the implied warranty of              |
	| MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the               |
	| GNU General Public License for more details.                                |
	|                                                                             |
	| You should have received a copy of the GNU General Public License           |
	| along with this program; if not, write to the Free Software                 |
	| Foundation, Inc., 59 Temple Place - Suite 330, Boston, MA  02111-1307, USA. |
	+-----------------------------------------------------------------------------+
*/
require_once('./Customizing/global/plugins/Services/Repository/RepositoryObject/OpenCast/classes/class.ilOpenCastPlugin.php');
include_once('./Services/Repository/classes/class.ilObjectPluginListGUI.php');
require_once('class.ilObjOpenCastGUI.php');

/**
 * ListGUI implementation for OpenCast object plugin. This one
 * handles the presentation in container items (categories, courses, ...)
 * together with the corresponfing ...Access class.
 *
 * PLEASE do not create instances of larger classes here. Use the
 * ...Access class to get DB data and keep it small.
 *
 * @author        Fabian Schmid <fs@studer-raimann.ch>
 * @author        Gabriel Comte <gc@studer-raimann.ch>
 *
 *
 * @version       1.0.00
 */
class ilObjOpenCastListGUI extends ilObjectPluginListGUI {

	/**
	 * @var ilOpenCastPlugin
	 */
	public $plugin;


	public function initType() {
		$this->setType(ilOpenCastPlugin::XOCT);
	}


	/**
	 * @return string
	 */
	public function getGuiClass() {
		return 'ilObjOpenCastGUI';
	}


	/**
	 * @return array
	 */
	public function initCommands() {

		// Always set
		$this->timings_enabled = true;
		$this->subscribe_enabled = true;
		$this->payment_enabled = false;
		$this->link_enabled = false;
		$this->info_screen_enabled = true;
		$this->delete_enabled = true;
		$this->notes_enabled = true;
		$this->comments_enabled = true;

		// Should be overwritten according to status
		$this->cut_enabled = true;
		$this->copy_enabled = true;

		$commands = array(
			array(
				'permission' => 'read',
				'cmd' => ilObjOpenCastGUI::CMD_SHOW_CONTENT,
				'default' => true,
			),
			array(
				'permission' => 'write',
				'cmd' => ilObjOpenCastGUI::CMD_REDIRECT_SETTING,
				'lang_var' => 'edit'
			)
		);

		return $commands;
	}


	function insertDeleteCommand() {
		if ($this->std_cmd_only)
		{
			return;
		}

		if(is_object($this->getContainerObject()) and
			$this->getContainerObject() instanceof ilAdministrationCommandHandling)
		{
			if($this->checkCommandAccess('delete','',$this->ref_id,$this->type))
			{
				$this->ctrl->setParameterByClass("ilObjOpenCastGUI",'item_ref_id',$this->getCommandId());
				$cmd_link = $this->ctrl->getLinkTargetByClass("ilObjOpenCastGUI", "delete");
				$this->insertCommand($cmd_link, $this->lng->txt("delete"));
				$this->adm_commands_included = true;
				return true;
			}
			return false;
		}

		if($this->checkCommandAccess('delete','',$this->ref_id,$this->type))
		{
			$this->ctrl->setParameterByClass("ilObjOpenCastGUI", "ref_id",
				$this->container_obj->object->getRefId());
			$this->ctrl->setParameterByClass("ilObjOpenCastGUI", "item_ref_id", $this->getCommandId());
			$cmd_link = $this->ctrl->getLinkTargetByClass("ilObjOpenCastGUI", "deleteObject");
			$this->insertCommand($cmd_link, $this->lng->txt("delete"), "",
				"");
			$this->adm_commands_included = true;
		}
	}


	/**
	 * @param bool $get_exceoptions
	 * @return xoctSeries
	 * @throws Exception
	 */
	protected function getSeries($get_exceoptions = false) {
		$xoctSeries = new xoctSeries();
		try {
			$xoctOpenCast = $this->getOpenCast($get_exceoptions);
			if ($xoctOpenCast instanceof xoctOpenCast) {
				$xoctSeries = $xoctOpenCast->getSeries();
			}
		} catch (xoctException $e) {
			if ($get_exceoptions) {
				throw $e;
			}
		}

		return $xoctSeries;
	}


	/**
	 * @param bool $get_exceoptions
	 * @return ActiveRecord|xoctOpenCast
	 * @throws xoctException
	 */
	protected function getOpenCast($get_exceoptions = false) {
		$xoctOpenCast = new xoctOpenCast();
		try {
			xoctConf::setApiSettings();
			$xoctOpenCast = xoctOpenCast::find($this->obj_id);
		} catch (xoctException $e) {
			if ($get_exceoptions) {
				throw $e;
			}
		}

		return $xoctOpenCast;
	}


	/**
	 * @return string
	 * @throws xoctException
	 */
	public function getTitle() {
		$title = $this->getSeries()->getTitle();
		return $title ? $title : parent::getTitle();
	}


	/**
	 * @return string
	 * @throws xoctException
	 */
	function getDescription() {
		$description = $this->getSeries()->getDescription();
		return $description ? $description : parent::getDescription();
	}


	/**
	 * Get item properties
	 *
	 * @return    array        array of property arrays:
	 *                        'alert' (boolean) => display as an alert property (usually in red)
	 *                        'property' (string) => property name
	 *                        'value' (string) => property value
	 */
	public function getCustomProperties($a_prop) {

		$props = parent::getCustomProperties(array());
		try {
			$xoctOpenCast = $this->getOpenCast(true);
			if (!$xoctOpenCast instanceof xoctOpenCast) {
				return $props;
			}
			$xoctOpenCast->getSeries();

			if (!$xoctOpenCast->isObjOnline()) {
				$props[] = array(
					'alert' => true,
					'newline' => true,
					'property' => 'Status',
					'value' => 'Offline',
					'propertyNameVisible' => true
				);
			}
		} catch (xoctException $e) {
			$props[] = array(
				'alert' => true,
				'newline' => true,
				'property' => 'API',
				'value' => $e->getMessage(),
				'propertyNameVisible' => false
			);
		}

		return $props;
	}


	/**
	 * get all alert properties
	 *
	 * @return array
	 */
	public function getAlertProperties() {
		$alert = array();
		foreach ((array)$this->getCustomProperties(array()) as $prop) {
			if ($prop['alert'] == true) {
				$alert[] = $prop;
			}
		}

		return $alert;
	}


	/**
	 * @param $unix_timestamp
	 *
	 * @return string formatted date
	 */

	public static function format_date_time($unix_timestamp) {
		global $lng;

		$now = time();
		$today = $now - $now % (60 * 60 * 24);
		$yesterday = $today - 60 * 60 * 24;

		if ($unix_timestamp < $yesterday) {
			// given date is older than two days
			$date = date('d. M Y', $unix_timestamp);
		} elseif ($unix_timestamp < $today) {
			// given date yesterday
			$date = $lng->txt('yesterday');
		} else {
			// given date is today
			$date = $lng->txt('today');
		}

		return $date . ', ' . date('H:i', $unix_timestamp);
	}
}

?>
