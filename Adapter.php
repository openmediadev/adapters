<?php

class AdapterError extends Exception {
};

class Adapter {

	/**
	 * Checks whether the adapter is currently working.
	 *
	 * @return Boolean 
	 */
	public function adapterIsWorking() {

		try {
			$item = $this->readItemByID($this->adapterTestItemID());
		} catch (Exception $e) {
			return ['error'=>'Could not read item. '.$e->getMessage(), 'item'=>[]];
		}

		if (!$item->title) return ['error'=>'Item title is missing.','item'=>$item];

		foreach (['id','description','duration','airtime','channel','image','contributor'] as $key) {
			if (!$item->$key) return ['warning'=>'Field "'.$key.'" is missing in item.','item'=>$item];
		}

		try {
			$channel = $this->readChannel($this->adapterTestChannelID());
		} catch (Exception $e) {
			return ['error'=>'Could not read channel. '.$e->getMessage(), 'channel'=>[]];
		}

		if (!$channel->name) return ['error'=>'Channel name is missing.','channel'=>$channel];

		foreach (['id','description','publisher'] as $key) {
			if (!$item->$key) return ['warning'=>'Field "'.$key.'" is missing in channel.','channel'=>$channel];
		}

		return true;
	}

	protected function adapterTestItemID() {
		throw $this->createError('The adapter does not implement adapterTestItemID().');
	}

	protected function adapterTestChannelID() {
		throw $this->createError('The adapter does not implement adapterTestChannelID().');
	}

	/**
	 * Extract a string
	 *
	 * @param result A reference to the variable where the string will be stored
	 * @param source A reference to the source string
	 * @param prefix The string which preceds the results
	 * @param suffix The string which follows the result
	 * @param offset The offset (optional)
	 * @return boolean The position of the result in the source
	 */
	public static function getSubstring(&$result, &$source, $prefix, $suffix, $offset = 0) {
	
		$result = null;
		
		$pos1 = strpos($source, $prefix, $offset);
	
		if($pos1===false) return false;
		
		$pos1 += strlen($prefix);
		$pos2 = strpos($source,$suffix,$pos1);
		
		$result = substr($source,$pos1,($pos2-$pos1));
		
		return $pos1;
	}
	
	/**
	 * Check if a HTTP resource exists
	 *
	 * @param URL The URL to check
	 * @return boolean The result
	 */
	public static function urlExists($URL) {
		$info = get_headers($URL);
		$status_code = $info[0];
		return strpos($status_code, '404') === false && strpos($status_code, '412') === false;
	}

	/**
	 * Convert BR tags to nl
	 *
	 * @param string The string to convert
	 * @return string The converted string
	 */
	public static function br2nl($string)
	{
		return preg_replace('/\<br(\s*)?\/?\>/i', "\n", $string);
	}
	
	
	/**
	 * Create an error object with a reference to the current adapter.
	 *
	 * @param message The message for this error
	 * @return object An object of AdapterError
	 */
	public function createError($message)
	{
		$message = 'Error in Adapter '.$this->publisher.': '.$message;
		$error = new AdapterError($message);
		$error->adapter = $this;
		//$error->errorMessage = $message;
		return $error;
	}

	
	/**
	 * Get metadata of a channel
	 *
	 * @param ID The ID of the channel
	 * @return object The channel
	 */
	public function readChannel($ID)
	{
		return $this->createError("Reading a channel is not supported for this publisher");
	}


	/**
	 * Get a channel's feed
	 *
	 * @param ID The ID of the channel
	 * @return array A list of item IDs
	 */
	public function readChannelFeed($ID)
	{
		return $this->createError("Reading a channel feed is not supported for this publisher");
	}


	/**
	 * Add legacy data to a channel (image, fullEpisodeLength)
	 *
	 * @param channel The channel
	 */
	public function addChannelLegacyData(&$channel) {
		require_once('../data/lookup/' . CONFIGURATION_NAME . '/channels-de.php');
		global $channels, $channelsByID;

		$legacyData = $channels[strtolower($channel->name)];
		if (!$legacyData) $legacyData = $channelsByID[$channel->publisher.'-'.$channel->ID];

		if($legacyData) {
			
			if(isset($legacyData['image'])) {
				$channel->image = new ItemImage;
				$channel->image->small = $legacyData['image'];
				if(isset($legacyData['imageMedium']))
					$channel->image->medium = $legacyData['imageMedium'];
				if(isset($legacyData['teaser']))
					$channel->image->teaserSmall = $legacyData['teaser'];
				if(isset($legacyData['teaserMedium']))
					$channel->image->teaserMedium = $legacyData['teaserMedium'];
			}
			if(isset($legacyData['fullEpisodeLength']))
				$channel->fullEpisodeLength = $legacyData['fullEpisodeLength'];

			if(!$channel->description && isset($legacyData['description']))
				$channel->description = $legacyData['description'];

			if(isset($legacyData['tags']))
				$channel->tags = $legacyData['tags'];

			if(isset($legacyData['forceName'])) {
				$channel->name = $legacyData['forceName'];
			}

			if(isset($legacyData['shortName']))
				$channel->shortName = $legacyData['shortName'];

			if(isset($legacyData['defaultImage']))
				$channel->defaultImage = $legacyData['defaultImage'];

			if(isset($legacyData['isNew']))
				$channel->isNew = $legacyData['isNew'];

			if(isset($legacyData['hasNoFeed']))
				$channel->hasNoFeed = $legacyData['hasNoFeed'];

			if(@$legacyData['images']) {
				$channel->image = new ItemImage;
				$channel->image->small = 'http://wehlte.com/mediathek-app/mediathek/images/channel/' . $channel->publisher . '-' . $legacyData['ID'] . '-small.jpg';
				$channel->image->medium = 'http://wehlte.com/mediathek-app/mediathek//images/channel/' . $channel->publisher . '-' . $legacyData['ID'] . '-medium.jpg';

				if(isset($legacyData['imageLarge']))
					$channel->image->large = $legacyData['imageLarge'];
			}

			if(!$channel->name) {
				$channel->name = $legacyData['name'];
			}
				
		}
	}


	/**
	 * Get an item by ID with cache
	 *
	 * @param ID The ID of the item
	 */
	public function getCachedItemByID($ID) {
		// see, if the item is cached
		
		$cached_file_path = 
		cachedRequestFilename('item', array('ID'=>$ID, 'publisher'=>$this->publisher));
		
		if(file_exists($cached_file_path)) {
			return json_decode(file_get_contents($cached_file_path));
		}
		
		$item = $this->readItemByID($ID);
		if($item->error) return $item;
		storeItem($item);
		
		file_put_contents($cached_file_path, json_encode($item));
		
		return $item;
	}

	/**
	 * Get a channel with cache
	 *
	 * @param ID The ID of the channel
	 */
	public function getCachedChannel($ID) {
		// see, if the channel is cached
	}


	/**
	 * Get a channel with its items
	 *
	 * @param ID The ID of the channel
	 * @return object The channel with items
	 */
	const OptionIncludeItemsForVisionImpaired = 1 << 0;
	const OptionIncludeItemsForHearingImpaired = 1 << 1;

	public function readChannelWithItems($ID, $options = 0)
	{
		$channel = $this->readChannel($ID);
		if($channel->error) return $channel;
		
		$feed = $this->readChannelFeed($ID);
		if($feed->error) return $feed;
		
		$channel->items = array();
		foreach($feed as $value) {
			try {
				$item = $this->readItemByID($value);
			} catch (Exception $e) {}
			if ($item) {
				if ($this->shouldSkipItem($item, $options)) continue;
				$channel->items[] = $item;
			}
		}

		return $channel;
	}

	protected function shouldSkipItem($item, $options) {
		return
			($this->itemIsForHearingImpaired($item) && !($options & self::OptionIncludeItemsForHearingImpaired)) ||
			($this-> itemIsForVisionImpaired($item) && !($options & self::OptionIncludeItemsForVisionImpaired));
	}

	protected function itemIsForHearingImpaired($item) {
		if (strpos($item->title, 'Gebärdensprache') !== false) return true;
		return false;
	}

	protected function itemIsForVisionImpaired($item) {
		if (strpos($item->title, 'Hörfassung') !== false) return true;
		if (strpos($item->title, 'Audiodeskription') !== false) return true;
		return false;
	}

	/**
	 * Get the channels for this publisher
	 *
	 * @return array The list of channels
	 */
	public function readChannels()
	{
		// update the channels, if necessary
		$success = $this->updateChannelsList();
		if(!$success) return $this->createError("Not supported for this publisher");

		// read channels from cache
		$cached_file_path =
		cachedRequestFilename('channels', array('publisher'=>$this->publisher));
		$channels = json_decode(file_get_contents($cached_file_path));
		
		return $channels;
	}


	/**
	 * Update the channels for this publisher
	 *
	 * @return boolean Success
	 */
	public function updateChannelsList()
	{
		return false;
	}
	
	/**
	 * Get the duration from a "HH:MM:SS" or "MM:SS" string
	 *
	 * @param string The string to parse
	 * @return number The duration in seconds
	 */
	public function parseDuration($string) {

		if(!is_numeric(str_replace(':','',$string))) return null;
		
		if($string[0]*1==0) $string = substr($string,1);
		$split_duration = explode(':', $string);
		$n = count($split_duration);
		$result = $split_duration[$n-1] * 1 + $split_duration[$n-2] * 60;
		if($n == 3)
			$result += $split_duration[$n-3] * (60 * 60);
			
		return $result;
	
	}

	/**
	 * Optimize the title of an item
	 * Removes surrounding quotes and trim whitespace
	 *
	 * @param string The title
	 */
	public static function optimizeTitle(&$string)
	{
		if($string[0] == '"' && $string[strlen($string)-1] == '"') {
			$string = substr($string, 1, strlen($string)-2);
		}
		$string = trim($string);
	}


	protected static function sanitizeCacheID($str) {
		$str = str_replace('/','_',$str);
		$str = str_replace('|','__',$str);

    		$strip = array("~", "`", "!", "@", "#", "$", "%", "^", "&", "*", "(", ")", "=", "+", "[", "{", "]",
                   "}", "\\", "|", ";", ":", "\"", "'", "&#8216;", "&#8217;", "&#8220;", "&#8221;", "&#8211;", "&#8212;",
                   "â€”", "â€“", ",", "<", ".", ">", "/", "?");
		$str = trim(str_replace($strip, "", strip_tags($str)));
    		$str = preg_replace('/\s+/', "-", $str);

		return $str;
	}

	protected static function maxCacheAge($type) {

		$hours = 60*60;
		$days = $hours*24;

		// We don't have to cache RSS feeds, but the performance is just so much better.
		// caching feeds for 1 hour.
		if ($type == 'feed') return 1*$hours;

		// Cache items for 14 days, sometimes typos get corrected.
		if ($type == 'item') return 14*$days;

		// Cache channel metadata for a week.
		if ($type == 'channel') return 7*$days;

		// Used with ZDF. Cache metadata for a week.
		if ($type == 'itemmedia') return 7*$days;

		// Used with ZDF. Cache metadata for a week.
		if ($type == 'channelmeta') return 7*$days;
		if ($type == 'channelmeta2') return 7*$days;

		// Cache channels list for a week.
		if ($type == 'channels') return 7*$days;
		if ($type == 'channel-list') return 7*$days;

		throw new AdapterError('Unknown type in Adapter::maxCacheAge.');

	}

};

class Item {};
class Channel {};
