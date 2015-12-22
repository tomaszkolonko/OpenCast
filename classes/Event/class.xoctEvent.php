<?php
require_once('./Customizing/global/plugins/Services/Repository/RepositoryObject/OpenCast/classes/Object/class.xoctObject.php');
require_once('./Customizing/global/plugins/Services/Repository/RepositoryObject/OpenCast/classes/Request/class.xoctRequest.php');
require_once('./Customizing/global/plugins/Services/Repository/RepositoryObject/OpenCast/classes/Event/Publication/class.xoctPublication.php');
require_once('./Customizing/global/plugins/Services/Repository/RepositoryObject/OpenCast/classes/Request/class.xoctUploadFile.php');
require_once('./Customizing/global/plugins/Services/Repository/RepositoryObject/OpenCast/classes/Event/Publication/class.xoctMedia.php');
require_once('./Customizing/global/plugins/Services/Repository/RepositoryObject/OpenCast/classes/Conf/PublicationUsage/class.xoctPublicationUsage.php');

/**
 * Class xoctEvent
 *
 * @author Fabian Schmid <fs@studer-raimann.ch>
 */
class xoctEvent extends xoctObject {

	const STATE_SUCCEEDED = 'SUCCEEDED';
	const STATE_INSTANTIATED = 'INSTANTIATED';
	const STATE_ENCODING = 'RUNNING';
	const STATE_NOT_PUBLISHED = 'NOT_PUBLISHED';
	const STATE_FAILED = 'FAILED';
	const NO_PREVIEW = './Customizing/global/plugins/Services/Repository/RepositoryObject/OpenCast/templates/images/no_preview.png';
	const PRESENTER_SEP = ';';
	/**
	 * @var array
	 */
	public static $state_mapping = array(
		xoctEvent::STATE_SUCCEEDED => 'success',
		xoctEvent::STATE_INSTANTIATED => 'info',
		xoctEvent::STATE_ENCODING => 'info',
		xoctEvent::STATE_NOT_PUBLISHED => 'info',
		xoctEvent::STATE_FAILED => 'danger',
	);
	/**
	 * @var string
	 */
	protected $thumbnail_url = NULL;
	/**
	 * @var string
	 */
	protected $annotation_url = NULL;
	/**
	 * @var string
	 */
	protected $player_url = NULL;
	/**
	 * @var null
	 */
	protected $download_url = NULL;


	/**
	 * @param $identifier
	 *
	 * @return xoctEvent
	 */
	public static function find($identifier) {
		/**
		 * @var $xoctEvent xoctEvent
		 */
		$xoctEvent = parent::find($identifier);

		if ($xoctEvent->getProcessingState() != self::STATE_SUCCEEDED) {
			self::removeFromCache($identifier);
			$xoctEvent->read();
			self::cache($identifier, $xoctEvent);
		}

		return $xoctEvent;
	}


	/**
	 * @param array $filter
	 *
	 * @return xoctEvent[]
	 */
	public static function getFiltered(array $filter, $for_user = NULL, $for_role = NULL, $from = 0, $to = 99999) {
		$check_cache = count($filter) == 1 AND isset($filter['series']);
		if ($check_cache) {
			$key = 'unfiltered_list' . $filter['series'] . '_' . $for_user;
			$existing = xoctCache::getInstance()->get($key);
			if ($existing) {
				//				return $existing;
			}
		}

		/**
		 * @var $xoctEvent xoctEvent
		 */
		$request = xoctRequest::root()->events();
		if ($filter) {
			$filter_string = '';
			foreach ($filter as $k => $v) {
				$filter_string .= $k . ':' . $v . '';
			}

			$request->parameter('filter', $filter_string);
		}
		$request->parameter('limit', 1000);
		//$request->parameter('sign', true);
		$data = json_decode($request->get($for_user, array( $for_role )));
		$return = array();
		$i = 0;
		foreach ($data as $d) {
			if ($i < $from || $i > $to) {
				//				$return[] = array();
				//				continue;
			}
			$xoctEvent = xoctEvent::find($d->identifier);
			$return[] = $xoctEvent->getArrayForTable();
			$i ++;
		}
		if ($check_cache) {
			xoctCache::getInstance()->set($key, $return);
		}

		return $return;
	}


	/**
	 * @return array
	 */
	public function getArrayForTable() {
		return array(
			'identifier' => $this->getIdentifier(),
			'title' => $this->getTitle(),
			'description' => $this->getDescription(),
			'presenter' => $this->getPresenter(),
			'location' => $this->getLocation(),
			'created' => $this->getCreated()->format(DATE_ATOM),
			'created_unix' => $this->getCreated()->format('U'),
			'owner' => $this->getOwnerUsername(),
			'processing_state' => $this->getProcessingState()
		);
	}


	/**
	 * @param string $identifier
	 */
	public function __construct($identifier = '') {
		if ($identifier) {
			$this->setIdentifier($identifier);
			$this->read();
		}
	}


	public function read() {
		$data = json_decode(xoctRequest::root()->events($this->getIdentifier())->get());
		$this->loadFromStdClass($data);
		$this->loadMetadata();
		$created = new DateTime($data->created);
		$this->setCreated($created->add(new DateInterval('PT3600S'))); // OpenCast FIX
		$this->setStartTime(new DateTime($data->start_time));
		$this->loadPublications();
		if ($this->getIdentifier()) {
			$this->setSeriesIdentifier($this->getMetadata()->getField('isPartOf')->getValue());
		}
		$this->loadAcl();
		$this->setOwnerUsername($this->getMetadata()->getField('rightsHolder')->getValue());
		$this->setSource($this->getMetadata()->getField('source')->getValue());
		$this->initProcessingState();
	}


	/**
	 * @param xoctUser $xoctUser
	 *
	 * @return bool
	 * @throws xoctException
	 */
	public function hasWriteAccess(xoctUser $xoctUser) {
		if ($this->isOwner($xoctUser)) {
			return true;
		}
	}


	/**
	 * @param xoctUser $xoctUser
	 *
	 * @return bool
	 * @throws xoctException
	 */
	public function hasReadAccess(xoctUser $xoctUser) {
		if ($this->isOwner($xoctUser)) {
			return true;
		}

		$role_names = array();
		foreach (xoctGroup::getAllGroupParticipantsOfUser($this->getSeriesIdentifier(), $xoctUser) as $xoctGroupParticipant) {
			$role_names[] = $xoctGroupParticipant->getXoctUser()->getIVTRoleName();
		}
		if ($this->getOwnerAcl() instanceof xoctAcl) {
			//			echo '<pre>' . print_r($role_names, 1) . '</pre>';
			//			echo '<pre>' . print_r($this->getOwnerAcl()->getRole(), 1) . '</pre>';
		}

		if ($this->getOwnerAcl() instanceof xoctAcl && in_array($this->getOwnerAcl()->getRole(), $role_names)) {
			return true;
		}

		$role_names_invitations = array();
		foreach (xoctInvitation::getAllInvitationsOfUser($this->getIdentifier(), $xoctUser) as $xoctIntivation) {
			$xoctUserInvitation = xoctUser::getInstance(new ilObjUser($xoctIntivation->getOwnerId()));
			$role_names_invitations[] = $xoctUserInvitation->getIVTRoleName();
		}

		if ($this->getOwnerAcl() instanceof xoctAcl && in_array($this->getOwnerAcl()->getRole(), $role_names_invitations)) {
			return true;
		}

		return false;
	}


	/**
	 * @param xoctUser $xoctUser
	 *
	 * @return bool
	 */
	public function isOwner(xoctUser $xoctUser) {
		$xoctAcl = $this->getOwnerAcl();
		if (!$xoctAcl instanceof xoctAcl) {
			return false;
		}
		if ($xoctAcl->getRole() == $xoctUser->getIVTRoleName()) {
			return true;
		}
	}


	/**
	 * @param bool|false $auto_publish
	 */
	public function create($auto_publish = false) {
		$data = array();

		$this->setMetadata(xoctMetadata::getSet(xoctMetadata::FLAVOR_DUBLINCORE_EPISODES));
		$this->updateMetadataFromFields();

		$data['metadata'] = json_encode(array( $this->getMetadata()->__toStdClass() ));
		$data['processing'] = json_encode($this->getProcessing($auto_publish));
		$data['acl'] = json_encode($this->getAcls());

		$presenter = xoctUploadFile::getInstanceFromFileArray('file_presenter');
		$data['presenter'] = $presenter->getCurlString();

		$return = json_decode(xoctRequest::root()->events()->post($data));

		$this->setIdentifier($return->identifier);
	}


	public function update() {
		// Metadata
		$this->updateMetadataFromFields();
		$this->getMetadata()->removeField('identifier');
		$this->getMetadata()->removeField('isPartOf');
		$this->getMetadata()->removeField('createdBy'); // can't be updated at the moment

		$data['metadata'] = json_encode(array( $this->getMetadata()->__toStdClass() ));

		// All Data
		xoctRequest::root()->events($this->getIdentifier())->post($data);
		$this->updateAcls();
		self::removeFromCache($this->getIdentifier());
	}


	public function updateAcls() {
		$xoctAclStandardSets = new xoctAclStandardSets();
		foreach ($xoctAclStandardSets->getAcls() as $acl) {
			$this->addAcl($acl);
		}

		xoctRequest::root()->events($this->getIdentifier())->acl()->put(array( 'acl' => json_encode($this->getAcls()) ));
		self::removeFromCache($this->getIdentifier());
	}


	public function updateSeries() {
		$this->updateMetadataFromFields();
		$this->getMetadata()->getField('isPartOf')->setValue($this->getSeriesIdentifier());
		$data['metadata'] = json_encode(array( $this->getMetadata()->__toStdClass() ));
		xoctRequest::root()->events($this->getIdentifier())->post($data);
		self::removeFromCache($this->getIdentifier());
	}


	/**
	 * @return null|xoctAcl
	 */
	public function getOwnerAcl() {
		static $owner_acl;
		if (isset($owner_acl[$this->getIdentifier()])) {
			return $owner_acl[$this->getIdentifier()];
		}
		foreach ($this->getAcls() as $acl) {
			if (strpos($acl->getRole(), str_replace('{IDENTIFIER}', '', xoctUser::getIdentifierPrefix())) !== false) {
				$owner_acl[$this->getIdentifier()] = $acl;

				return $acl;
			}
		}
		$owner_acl[$this->getIdentifier()] = NULL;

		return NULL;
	}


	/**
	 * @return null|xoctUser
	 */
	public function getOwner() {
		$acl = $this->getOwnerAcl();
		if ($acl instanceof xoctAcl) {
			$usr_id = xoctUser::lookupUserIdForIVTRole($acl->getRole());
			if ($usr_id) {
				return xoctUser::getInstance(new ilObjUser($usr_id));
			}
		} else {
			return NULL;
		}
	}


	/**
	 * @param xoctUser $xoctUser
	 *
	 * @throws xoctException
	 */
	public function setOwner(xoctUser $xoctUser) {
		$this->removeAllOwnerAcls();
		$acl = new xoctAcl();
		$acl->setAction(xoctAcl::READ);
		$acl->setAllow(true);
		$acl->setRole($xoctUser->getIVTRoleName());
		$this->addAcl($acl);

		$acl = new xoctAcl();
		$acl->setAction(xoctAcl::WRITE);
		$acl->setAllow(true);
		$acl->setRole($xoctUser->getIVTRoleName());
		$this->addAcl($acl);

		$acl = new xoctAcl();
		$acl->setAction(xoctAcl::READ);
		$acl->setAllow(true);
		$acl->setRole($xoctUser->getUserRoleName());
		$this->addAcl($acl);

		$acl = new xoctAcl();
		$acl->setAction(xoctAcl::WRITE);
		$acl->setAllow(true);
		$acl->setRole($xoctUser->getUserRoleName());
		$this->addAcl($acl);

		$this->getMetadata()->getField('rightsHolder')->setValue($xoctUser->getNamePresentation());
	}


	public function removeOwner() {
		$this->removeAllOwnerAcls();
		$this->getMetadata()->getField('rightsHolder')->setValue('');
	}


	public function removeAllOwnerAcls() {
		foreach ($this->getAcls() as $i => $acl) {
			if (strpos($acl->getRole(), str_replace('{IDENTIFIER}', '', xoctUser::getIdentifierPrefix())) !== false) {
				unset($this->acls[$i]);
			}
		}
		sort($this->acls);
	}


	/**
	 * @return bool
	 */
	public function delete() {
		xoctRequest::root()->events($this->getIdentifier())->delete();

		return true;
	}


	/**
	 * @return string
	 */
	public function getThumbnailUrl() {
		if (!$this->thumbnail_url) {
			$this->thumbnail_url = xoctSecureLink::sign($this->getPublicationMetadataForUsage(xoctPublicationUsage::find(xoctPublicationUsage::USAGE_THUMBNAIL))
				->getUrl());
			if (!$this->thumbnail_url) {
				$this->thumbnail_url = xoctSecureLink::sign($this->getPublicationMetadataForUsage(xoctPublicationUsage::find(xoctPublicationUsage::USAGE_THUMBNAIL_FALLBACK))
					->getUrl());
			}
			if (!$this->thumbnail_url) {
				$this->thumbnail_url = self::NO_PREVIEW;
			}
		}

		return $this->thumbnail_url;
	}


	/**
	 * @return null|string
	 */
	public function getAnnotationLink() {
		if (!isset($this->annotation_url)) {
			$url = $this->getPublicationMetadataForUsage(xoctPublicationUsage::find(xoctPublicationUsage::USAGE_ANNOTATE))->getUrl();
			$this->annotation_url = $url; // doesn't need to be signed
		}

		return $this->annotation_url;
	}


	/**
	 * @return null|string
	 */
	public function getPlayerLink() {
		if (!isset($this->player_url)) {
			$url = $this->getPublicationMetadataForUsage(xoctPublicationUsage::find(xoctPublicationUsage::USAGE_PLAYER))->getUrl();
			$this->player_url = xoctSecureLink::sign($url);
		}

		return $this->player_url;
	}


	/**
	 * @return null|string
	 */
	public function getDownloadLink() {
		if (!isset($this->download_url)) {
			$url = $this->getPublicationMetadataForUsage(xoctPublicationUsage::find(xoctPublicationUsage::USAGE_DOWNLOAD))->getUrl();
			$this->download_url = xoctSecureLink::sign($url);
		}

		return $this->download_url;
	}


	/**
	 * @param $xoctPublicationUsage
	 *
	 * @return xoctPublication
	 */
	public function getPublicationMetadataForUsage($xoctPublicationUsage) {
		if (!$xoctPublicationUsage instanceof xoctPublicationUsage) {
			return new xoctPublication();
		}
		/**
		 * @var $xoctPublicationUsage  xoctPublicationUsage
		 * @var $attachment            xoctAttachment
		 * @var $media                 xoctMedia
		 */
		$medias = array();
		$attachments = array();
		foreach ($this->getPublications() as $publication) {
			$medias = array_merge($medias, $publication->getMedia());
			$attachments = array_merge($attachments, $publication->getAttachments());
		}
		switch ($xoctPublicationUsage->getMdType()) {
			case xoctPublicationUsage::MD_TYPE_ATTACHMENT:
				foreach ($attachments as $attachment) {
					if ($attachment->getFlavor() == $xoctPublicationUsage->getFlavor()) {
						return $attachment;
					}
				}
				break;
			case xoctPublicationUsage::MD_TYPE_MEDIA:
				foreach ($medias as $media) {
					if ($media->getFlavor() == $xoctPublicationUsage->getFlavor()) {
						return $media;
					}
				}
				break;
			case xoctPublicationUsage::MD_TYPE_PUBLICATION_ITSELF:
				foreach ($this->getPublications() as $publication) {
					if ($publication->getChannel() == $xoctPublicationUsage->getChannel()) {
						return $publication;
					}
				}
				break;
		}

		return new xoctPublication();
	}


	protected function loadPublications() {
		$data = json_decode(xoctRequest::root()->events($this->getIdentifier())->publications()->get());

		$publications = array();
		foreach ($data as $d) {
			$p = new xoctPublication();
			$p->loadFromStdClass($d);
			$publications[] = $p;
		}
		$this->setPublications($publications);
	}


	protected function loadAcl() {
		$data = json_decode(xoctRequest::root()->events($this->getIdentifier())->acl()->get());
		$acls = array();
		foreach ($data as $d) {
			$p = new xoctAcl();
			$p->loadFromStdClass($d);
			$acls[] = $p;
		}
		$this->setAcls($acls);
	}


	public function loadMetadata() {
		if ($this->getIdentifier()) {
			$data = json_decode(xoctRequest::root()->events($this->getIdentifier())->metadata()->get());
			if (is_array($data)) {
				foreach ($data as $d) {
					if ($d->flavor == xoctMetadata::FLAVOR_DUBLINCORE_EPISODES) {
						$xoctMetadata = new xoctMetadata();
						$xoctMetadata->loadFromStdClass($d);
						$this->setMetadata($xoctMetadata);
					}
				}
			}
		}
		if (!$this->getMetadata()) {
			$this->setMetadata(xoctMetadata::getSet(xoctMetadata::FLAVOR_DUBLINCORE_SERIES));
		}
	}


	protected function initProcessingState() {
		switch ($this->processing_state) {
			case self::STATE_SUCCEEDED:
				if (count($this->publication_status) < 2) {
					$this->setProcessingState(xoctEvent::STATE_NOT_PUBLISHED);
				}
				break;
			case '': // FIX: OpenCast delivers sometimes a empty state. this patch will be removed after fix on OpenCast
				$this->setProcessingState(xoctEvent::STATE_SUCCEEDED);
				break;
		}
	}


	/**
	 * @var string
	 */
	protected $identifier = '';
	/**
	 * @var int
	 */
	protected $archive_version;
	/**
	 * @var DateTime
	 */
	protected $created;
	/**
	 * @var string
	 */
	protected $creator;
	/**
	 * @var Array
	 */
	protected $contributors;
	/**
	 * @var string
	 */
	protected $description;
	/**
	 * @var int
	 */
	protected $duration;
	/**
	 * @var bool
	 */
	protected $has_previews;
	/**
	 * @var string
	 */
	protected $location;
	/**
	 * @var string
	 */
	protected $presenter;
	/**
	 * @var array
	 */
	protected $publication_status;
	/**
	 * @var array
	 */
	protected $processing_state;
	/**
	 * @var DateTime
	 */
	protected $start_time;
	/**
	 * @var array
	 */
	protected $subjects;
	/**
	 * @var string
	 */
	protected $title;
	/**
	 * @var xoctPublication[]
	 */
	protected $publications;
	/**
	 * @var xoctMetadata
	 */
	protected $metadata = NULL;
	/**
	 * @var xoctAcl[]
	 */
	protected $acls = array();
	/**
	 * @var string
	 */
	protected $series_identifier = '';
	/**
	 * @var string
	 */
	protected $owner_username = '';
	/**
	 * @var string
	 */
	protected $source = '';


	/**
	 * @return string
	 */
	public function getIdentifier() {
		return $this->identifier;
	}


	/**
	 * @param string $identifier
	 */
	public function setIdentifier($identifier) {
		$this->identifier = $identifier;
	}


	/**
	 * @return int
	 */
	public function getArchiveVersion() {
		return $this->archive_version;
	}


	/**
	 * @param int $archive_version
	 */
	public function setArchiveVersion($archive_version) {
		$this->archive_version = $archive_version;
	}


	/**
	 * @return DateTime
	 */
	public function getCreated() {
		return $this->created ? $this->created : new DateTime();
	}


	/**
	 * @param DateTime $created
	 */
	public function setCreated($created) {
		$this->created = $created;
	}


	/**
	 * @return string
	 */
	public function getCreator() {
		return $this->creator;
	}


	/**
	 * @param string $creator
	 */
	public function setCreator($creator) {
		$this->creator = $creator;
	}


	/**
	 * @return Array
	 */
	public function getContributors() {
		return $this->contributors;
	}


	/**
	 * @param Array $contributors
	 */
	public function setContributors($contributors) {
		$this->contributors = $contributors;
	}


	/**
	 * @return string
	 */
	public function getDescription() {
		return $this->description;
	}


	/**
	 * @param string $description
	 */
	public function setDescription($description) {
		$this->description = $description;
	}


	/**
	 * @return int
	 */
	public function getDuration() {
		return $this->duration;
	}


	/**
	 * @param int $duration
	 */
	public function setDuration($duration) {
		$this->duration = $duration;
	}


	/**
	 * @return boolean
	 */
	public function isHasPreviews() {
		return $this->has_previews;
	}


	/**
	 * @param boolean $has_previews
	 */
	public function setHasPreviews($has_previews) {
		$this->has_previews = $has_previews;
	}


	/**
	 * @return string
	 */
	public function getLocation() {
		return $this->location;
	}


	/**
	 * @param string $location
	 */
	public function setLocation($location) {
		$this->location = $location;
	}


	/**
	 * @return Array
	 */
	public function getPresenter() {
		return $this->presenter;
	}


	/**
	 * @param Array $presenter
	 */
	public function setPresenter($presenter) {
		$this->presenter = $presenter;
	}


	/**
	 * @return array
	 */
	public function getPublicationStatus() {
		return $this->publication_status;
	}


	/**
	 * @param array $publication_status
	 */
	public function setPublicationStatus($publication_status) {
		$this->publication_status = $publication_status;
	}


	/**
	 * @return array
	 */
	public function getProcessingState() {
		$this->initProcessingState();
		return $this->processing_state;
	}


	/**
	 * @param array $processing_state
	 */
	public function setProcessingState($processing_state) {
		$this->processing_state = $processing_state;
	}


	/**
	 * @return DateTime
	 */
	public function getStartTime() {
		return $this->start_time;
	}


	/**
	 * @param DateTime $start_time
	 */
	public function setStartTime($start_time) {
		$this->start_time = $start_time;
	}


	/**
	 * @return array
	 */
	public function getSubjects() {
		return $this->subjects;
	}


	/**
	 * @param array $subjects
	 */
	public function setSubjects($subjects) {
		$this->subjects = $subjects;
	}


	/**
	 * @return string
	 */
	public function getTitle() {
		return $this->title;
	}


	/**
	 * @param string $title
	 */
	public function setTitle($title) {
		$this->title = $title;
	}


	/**
	 * @return xoctPublication[]
	 */
	public function getPublications() {
		return $this->publications;
	}


	/**
	 * @param xoctPublication[] $publications
	 */
	public function setPublications($publications) {
		$this->publications = $publications;
	}


	/**
	 * @return xoctMetadata
	 */
	public function getMetadata() {
		return $this->metadata;
	}


	/**
	 * @param xoctMetadata $metadata
	 */
	public function setMetadata(xoctMetadata $metadata) {
		$this->metadata = $metadata;
	}


	/**
	 * @return xoctAcl[]
	 */
	public function getAcls() {
		return $this->acls;
	}


	/**
	 * @param xoctAcl[] $acls
	 */
	public function setAcls($acls) {
		$this->acls = $acls;
	}


	/**
	 * @param xoctAcl $acl
	 *
	 * @return bool
	 */
	public function addAcl(xoctAcl $acl) {
		foreach ($this->getAcls() as $existingAcl) {
			if ($acl->getRole() == $existingAcl->getRole() && $acl->getAction() == $existingAcl->getAction()) {
				return false;
			}
		}

		$this->acls[] = $acl;

		return true;
	}


	/**
	 * @return string
	 */
	public function getSeriesIdentifier() {
		return $this->series_identifier;
	}


	/**
	 * @param string $series_identifier
	 */
	public function setSeriesIdentifier($series_identifier) {
		$this->series_identifier = $series_identifier;
	}


	/**
	 * @return string
	 */
	public function getOwnerUsername() {
		return $this->owner_username;
	}


	/**
	 * @param string $owner_username
	 */
	public function setOwnerUsername($owner_username) {
		$this->owner_username = $owner_username;
	}


	/**
	 * @return string
	 */
	public function getSource() {
		return $this->source;
	}


	/**
	 * @param string $source
	 */
	public function setSource($source) {
		$this->source = $source;
	}


	protected function updateMetadataFromFields() {
		$title = $this->getMetadata()->getField('title');
		$title->setValue($this->getTitle());

		$description = $this->getMetadata()->getField('description');
		$description->setValue($this->getDescription());

		$location = $this->getMetadata()->getField('location');
		$location->setValue($this->getLocation());

		$subjects = $this->getMetadata()->getField('subjects');
		$subjects->setValue(array());

		$is_part_of = $this->getMetadata()->getField('isPartOf');
		$is_part_of->setValue($this->getSeriesIdentifier());

		$startDate = $this->getMetadata()->getField('startDate');
		$startDate->setValue(date('Y-m-d'));

		$startTime = $this->getMetadata()->getField('startTime');
		$startTime->setValue(date('H:i'));

		//		$source = $this->getMetadata()->getField('source');
		//		$source->setValue($this->getSource());

		$presenter = $this->getMetadata()->getField('creator');
		$presenter->setValue(explode(self::PRESENTER_SEP, $this->getPresenter()));
	}


	/**
	 * @param $fieldname
	 * @param $value
	 *
	 * @return mixed
	 */
	protected function wakeup($fieldname, $value) {
		switch ($fieldname) {
			case 'presenter':
				return implode(self::PRESENTER_SEP, $value);
			default:
				return $value;
		}
	}


	/**
	 * @param $fieldname
	 * @param $value
	 *
	 * @return mixed
	//	 */
	//	protected function sleep($fieldname, $value) {
	//		switch ($fieldname) {
	//			case 'presenter':
	//				return explode(self::PRESENTER_SEP, $value);
	//			default:
	//				return $value;
	//		}
	//	}

	/**
	 * @param bool|false $auto_publish
	 * @return stdClass
	 */
	protected function getProcessing($auto_publish = false) {
		require_once('./Customizing/global/plugins/Services/Repository/RepositoryObject/OpenCast/classes/Conf/class.xoctConf.php');
		$processing = new stdClass();
		$processing->workflow = xoctConf::get(xoctConf::F_WORKFLOW);
		$processing->configuration = new stdClass();
		$processing->configuration->flagForCutting = 'false';
		$processing->configuration->flagForReview = 'false';
		$processing->configuration->publishToEngage = 'false';
		$processing->configuration->publishToHarvesting = 'false';
		$processing->configuration->straightToPublishing = 'false';
		$processing->configuration->autopublish = $auto_publish ? 'true' : 'false';

		return $processing;
	}
}