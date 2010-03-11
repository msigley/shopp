<?php
/**
 * Asset class
 * Catalog product assets (metadata, images, downloads)
 *
 * @author Jonathan Davis
 * @version 1.0
 * @copyright Ingenesis Limited, 28 March, 2008
 * @package shopp
 **/

/**
 * FileAsset class
 * 
 * Foundational class to provide a useable asset framework built on the meta
 * system introduced in Shopp 1.1.
 *
 * @author Jonathan Davis
 * @since 1.1
 * @package shopp
 * @subpackage asset
 **/
class FileAsset extends MetaObject {

	var $mime;
	var $size;
	var $storage;
	var $uri;
	var $context = 'product';
	var $type = 'asset';
	var $_xcols = array('mime','size','storage','uri');

	function __construct ($id=false) {
		global $Shopp;
		$this->init(self::$table);
		$this->extensions();
		if (!$id) return;
		$this->load($id);

	}
		
	function load ($id) {
		if (is_array($id)) parent::load($id);
		parent::load(array('id'=>$id,'type'=>$this->type));
		if (empty($this->id)) return false;
		$this->expopulate();
	}
	
	function expopulate () {
		if (is_object($this->value)) {
			$properties = $this->value;
			unset($this->value);
			$this->copydata($properties);
		}
	}
	
	function save () {
		$this->value = new stdClass();
		foreach ($this->_xcols as $col)
			$this->value->{$col} = $this->{$col};
		parent::save();
	}
	
	/**
	 * Store the file data using the preferred storage engine
	 *
	 * @author Jonathan Davis
	 * @since 1.1
	 * 
	 * @return void
	 **/
	function store ($data,$type='binary') {
		$Engine = $this->_engine();
		$this->uri = $Engine->save($this,$data,$type);
		if ($this->uri === false) return false;
	}
	
	function retrieve () {
		$Engine = $this->_engine();
		return $Engine->load($this->uri);
	}
	
	function found () {
		if (!empty($this->data)) return true;
		$Engine = $this->_engine();
		return $Engine->exists($this->uri);
	}
	
	function &_engine () {
		global $Shopp;

		if (!empty($this->storage)) {
			// Use the storage engine setting of the asset
			if (isset($Shopp->Storage->active[$this->storage])) {
				$Engine = $Shopp->Storage->active[$this->storage];
			} else {
				$Module = new ModuleFile(SHOPP_PATH."/storage/",$this->storage.".php");
				$Engine = $Module->load();
			}
		} elseif (isset($Shopp->Storage->engines[$this->type])) {
			// Pick storage engine from Shopp-loaded engines by type of asset
			$engine = $Shopp->Storage->engines[$this->type];
			$this->storage = $engine;
			$Engine = $Shopp->Storage->active[$engine];
		}
		if (!empty($Engine)) $Engine->context($this->type);
		
		return $Engine;
	}
	
	function extensions () {}
	
} // END class FileAsset

/**
 * ImageAsset class
 * 
 * A specific implementation of the FileAsset class that provides helper 
 * methods for imaging-specific tasks.
 *
 * @author Jonathan Davis
 * @since 1.1
 * @package shopp
 **/
class ImageAsset extends FileAsset {
	
	// Allowable settings
	var $_scaling = array('all','matte','crop','width','height');
	var $_sharpen = 500;
	var $_quality = 100;

	var $width;
	var $height;
	var $alt;
	var $title;
	var $filename;
	var $type = 'image';
	
	function output ($headers=true) {
		if ($headers) {
			header('Last-Modified: '.date('D, d M Y H:i:s', $this->created).' GMT');
			header("Content-type: {$this->mime}");
			if (!empty($this->filename))
				header("Content-Disposition: inline; filename=".$this->filename); 
			else header("Content-Disposition: inline; filename=image-".$this->id.".jpg");
			header("Content-Description: Delivered by WordPress/Shopp Image Server ({$this->storage})");
		}
		if (isset($this->data)) {
			echo $this->data;
			return;
		}
		$Engine = $this->_engine();
		$Engine->output($this->uri);
	}

	function scaled ($width,$height,$fit='all') {
		if (preg_match('/^\d+$/',$fit))
			$fit = $this->_scaling[$fit];

		$d = array('width'=>$this->width,'height'=>$this->height);
		switch ($fit) {
			case "width": return $this->scaledWidth($width,$height); break;
			case "height": return $this->scaledHeight($width,$height); break;
			case "crop":
			case "matte":
				$d['width'] = $width;
				$d['height'] = $height;
				break;
			case "all":
			default:
				if ($width/$this->width < $height/$this->height) return $this->scaledWidth($width,$height);
				else return $this->scaledHeight($width,$height);
				break;
		}
		
		return $d;
	}
	
	function scaledWidth ($width,$height) {
		$d = array('width'=>$this->width,'height'=>$this->height);
		$scale = $width / $this->width;
		$d['width'] = $width;
		$d['height'] = ceil($this->height * $scale);
		return $d;
	}
	
	function scaledHeight ($width,$height) {
		$d = array('width'=>$this->width,'height'=>$this->height);
		$scale = $height / $this->height;
		$d['height'] = $height;
		$d['width'] = ceil($this->width * $scale);
		return $d;
	}
	
	/**
	 * Generate a resizing request message
	 *
	 * @author Jonathan Davis
	 * @since 1.1
	 * 
	 * @return void
	 **/
	function resizing ($width,$height,$scale=false,$sharpen=false,$quality=false) {
		$key = (defined('SECRET_AUTH_KEY') && SECRET_AUTH_KEY != '')?SECRET_AUTH_KEY:DB_PASSWORD;
		$args = func_get_args();
		
		if ($args[1] == 0) $args[1] = $args[0];
		
		$message = '';
		foreach ($args as $arg) $message .= (!empty($message) && !empty($arg))?",$arg":$arg;
		$validation = crc32($key.$this->id.','.$message);
		$message .= ",$validation";
		return $message;
	}
	
	function extensions () {
		array_push($this->_xcols,'filename','width','height','alt','title');
	}
}

/**
 * ProductImage class
 * 
 * An ImageAsset used in a product context.
 *
 * @author Jonathan Davis
 * @since 1.1
 * @package shopp
 **/
class ProductImage extends ImageAsset {
	var $context = 'product';
	
	/**
	 * Truncate image data when stored in a session
	 * 
	 * A ProductImage can be stored in the session with a cart Item object. We
	 * strip out unnecessary fields here to keep the session data as small as
	 * possible.
	 *
	 * @author Jonathan Davis
	 * @since 1.1
	 * 
	 * @return array
	 **/
	function __sleep () {
		$ignore = array('numeral','created','modified','parent');
		$properties = get_object_vars($this);
		$session = array();
		foreach ($properties as $property => $value) {
			if (substr($property,0,1) == "_") continue;
			if (in_array($property,$ignore)) continue;
			$session[] = $property;
		}
		return $session;
	}
}

/**
 * CategoryImage class
 * 
 * An ImageAsset used in a category context.
 *
 * @author Jonathan Davis
 * @since 1.1
 * @package shopp
 * @subpackage asset
 **/
class CategoryImage extends ImageAsset {
	var $context = 'category';
}

/**
 * DownloadAsset class
 * 
 * A specific implementation of a FileAsset that includes helper methods 
 * for downloading routines.
 *
 * @author Jonathan Davis
 * @since 1.1
 * @package shopp
 * @subpackage asset
 **/
class DownloadAsset extends FileAsset {
	
	var $type = 'download';
	var $context = 'product';
	var $etag = "";
	
	function loadby_dkey ($key) {
		$db = &DB::get();
		require_once(SHOPP_MODEL_PATH."/Purchased.php");
		$pricetable = DatabaseObject::tablename(Price::$table);
		$Purchased = new Purchased($key,"dkey");
		$Purchase = new Purchase($Purchased->purchase);
		$record = $db->query("SELECT download.* FROM $this->_table AS download LEFT JOIN $pricetable AS pricing ON pricing.id=download.parent WHERE pricing.id=$Purchased->price AND download.context='price' AND download.type='download' LIMIT 1");
		$this->populate($record);
		$this->expopulate();
		$this->etag = $key;
	}
	
	function download ($dkey=false) {
		$found = $this->found();
		if (!$found) return false;
		
		if (!isset($found['redirect'])) {
			// Close the session in case of long download
			@session_write_close();

			// Don't want interference from the server
		    if (function_exists('apache_setenv')) @apache_setenv('no-gzip', 1);
		    @ini_set('zlib.output_compression', 0);
		
			set_time_limit(0);	// Don't timeout on long downloads
			// ob_end_clean();		// End any automatic output buffering
		
			header("Pragma: public");
			header("Cache-Control: maxage=1");
			header("Content-type: application/octet-stream"); 
			header("Content-Disposition: attachment; filename=\"".$this->name."\""); 
			header("Content-Description: Delivered by WordPress/Shopp ".SHOPP_VERSION);
		}
		$this->send();
		
		return true;
	}
	
	function send () {
		$Engine = $this->_engine();
		$Engine->output($this->uri,$this->etag);
	}
	

}

class ProductDownload extends DownloadAsset {
	var $context = 'product';
}

/**
 * StorageEngines class
 * 
 * Storage engine file manager to load storage engines that are active.
 *
 * @author Jonathan Davis
 * @since 1.1
 * @package shopp
 * @subpackage storage
 **/
class StorageEngines extends ModuleLoader {
	
	var $engines = array();
	var $activate = false;
	
	/**
	 * Initializes the shipping module loader
	 *
	 * @author Jonathan Davis
	 * @since 1.1
	 * 
	 * @return void Description...
	 **/
	function __construct () {
		$this->path = SHOPP_STORAGE;
		$this->installed();
		$this->activated();
		$this->load();
	}
	
	/**
	 * Determines the activated storage engine modules
	 *
	 * @author Jonathan Davis
	 * @since 1.1
	 * 
	 * @return array List of module names for the activated modules
	 **/
	function activated () {
		global $Shopp;
	
		$this->activated = array();

		$systems = array();
		$systems['image'] = $Shopp->Settings->get('image_storage');
		$systems['download'] = $Shopp->Settings->get('product_storage');
		
		foreach ($systems as $system => $storage) {
			foreach ($this->modules as $engine) {
				if ($engine->subpackage == $storage) {
					$this->activated[] = $engine->subpackage;
					$this->engines[$system] = $engine->subpackage;
					break; // Check for next system engine
				}
			}
		}

		return $this->activated;
	}
	
	/**
	 * Loads all the installed gateway modules for the payments settings
	 *
	 * @author Jonathan Davis
	 * @since 1.1
	 * 
	 * @return void
	 **/
	function settings () {
		$this->load(true);
	}
	
	/**
	 * Sets up the storage engine settings interfaces
	 *
	 * @author Jonathan Davis
	 * @since 1.1
	 * 
	 * @return void
	 **/
	function ui () {
		foreach ($this->active as $package => &$module)
			$module->setupui($package,$this->modules[$package]->name);
	}
	
}

/**
 * StorageEngine interface
 * 
 * Provides a template for storage engine modules to implement
 *
 * @author Jonathan Davis
 * @since 1.1
 * @package shopp
 * @subpackage storage
 **/
interface StorageEngine {
	
	/**
	 * Load a resource by the uri
	 *
	 * @author Jonathan Davis
	 * @since 1.1
	 * 
	 * @param string $uri The uniform resource indicator
	 * @return void
	 **/
	public function load($uri);
	
	/**
	 * Output the asset data of a given uri
	 *
	 * @author Jonathan Davis
	 * @since 1.1
	 * 
	 * @param string $uri The uniform resource indicator
	 * @return void
	 **/
	public function output($uri);
	
	/**
	 * Checks if the binary data of an asset exists
	 *
	 * @author Jonathan Davis
	 * @since 1.1
	 * 
	 * @param string $uri The uniform resource indicator
	 * @return boolean
	 **/
	public function exists($uri);
	
	/**
	 * Store the data for an asset
	 *
	 * @author Jonathan Davis
	 * @since 1.1
	 * 
	 * @param FileAsset $asset The parent asset for the data
	 * @param mixed $data The raw data to be stored
	 * @param string $type (optional) Type of data source, one of binary or file (file referring to a filepath)
	 * @return void
	 **/
	public function save($asset,$data,$type='binary');
	
}

/**
 * StorageModule class
 * 
 * A framework for storage engine modules.
 *
 * @author Jonathan Davis
 * @since 1.1
 * @package shopp
 * @subpackage storage
 **/
abstract class StorageModule {
	
	function __construct () {
		global $Shopp;
		$this->module = get_class($this);
		if (!isset($Shopp->Settings)) {
			$Settings = new Settings($this->module);
			$this->settings = $Settings->get($this->module);
		} else $this->settings = $Shopp->Settings->get($this->module);
	}
	
	function context ($setting) {}
	function settings () {}
	
	/**
	 * Generate the settings UI for the module
	 *
	 * @author Jonathan Davis
	 * @since 1.1
	 * 
	 * @param string $module The module class name
	 * @param string $name The formal name of the module
	 * @return void
	 **/
	function setupui ($module,$name) {
		$this->ui = new ModuleSettingsUI('storage',$module,$name,$this->settings['label'],$this->multi);
		$this->settings();
	}
		
	function output ($uri) {
		$data = $this->load($uri);
		header ("Content-length: ".strlen($data)); 
		echo $data;
	}
	
}

?>