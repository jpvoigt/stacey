<?php

Class Stacey {

	static $version = '1.0';
	
	function __construct($get) {
		$this->php_fixes();
		// it's easier to handle some redirection through php rather than relying on a more complex .htaccess file to do all the work
		if($this->handle_redirects()) return;
		// parse get request
		$r = new Renderer($get);
		// handle rendering of the page
		$r->render();
	}
	
	function php_fixes() {
		// in PHP/5.3.0 they added a requisite for setting a default timezone, this should be handled via the php.ini, but as we cannot rely on this, we have to set a default timezone ourselves
		if(function_exists('date_default_timezone_set')) date_default_timezone_set('Australia/Melbourne');
	}
	
	function handle_redirects() {
		// rewrite any calls to /index or /app back to /
		if(preg_match('/index|app\/?$/', $_SERVER['REQUEST_URI'])) {
			header('HTTP/1.1 301 Moved Permanently');
			header('Location: ../');
			return true;
		}
		// add trailing slash if required
		if(!preg_match('/\/$/', $_SERVER['REQUEST_URI'])) {
			header('HTTP/1.1 301 Moved Permanently');
			header('Location:'.$_SERVER['REQUEST_URI'].'/');
			return true;
		}
		return false;
	}
	
}

Class Helpers {
	
	static function sort_by_length($a,$b){
		if($a == $b) return 0;
		return (strlen($a) > strlen($b) ? -1 : 1);
	}

	static function list_files($dir, $regex, $folders_only = false) {
		$files = array();		
		$glob = ($folders_only) ? glob($dir."/*", GLOB_ONLYDIR) : glob($dir."/*");
		// loop through each glob result and push it to $dirs if it matches the passed regexp 
		foreach($glob as $file) {
			// strip out just the filename
			preg_match('/\/([^\/]+?)$/', $file, $slug);
			if(preg_match($regex, $slug[1])) $files[] = $slug[1];
		}
		// sort list in reverse-numeric order
		rsort($files, SORT_NUMERIC);
		return $files;
	}
	
	static function is_category($name, $dir = '../content') {
		// check if this folder contains inner folders - if it does, then it is a category
		foreach(Helpers::list_files($dir, '/.*/', true) as $folder) {
			if(preg_match('/'.$name.'$/', $folder)) {
				$inner_folders = Helpers::list_files('../content/'.$folder, '/.*/', true);
				if(!empty($inner_folders)) return true;
			}
		}
		// if the folder doesn't contain any inner folders, then it is not a category
		return false;
	}
	
}

Class Cache {

	var $page;
	var $cachefile;
	var $hash;
	
	function __construct($page) {
		// store reference to current page
		$this->page = $page;
		// turn a base64 of the full path to the page's content file into the name of the cache file
		$this->cachefile = './cache/'.base64_encode($this->page->content_file);
		//collect an md5 of all files
		$this->hash = $this->create_hash();
	}
	
	function check_expired() {
		// if cachefile doesn't exist, we need to create one
		if(!file_exists($this->cachefile)) return true;
		// compare new m5d to existing cached md5
		elseif($this->hash !== $this->get_current_hash()) return true;
		else return false;
	}
	
	function get_current_hash() {
		preg_match('/Stacey.*: (.+?)\s/', file_get_contents($this->cachefile), $matches);
		return $matches[1];
	}
	
	function write_cache() {
		echo "\n".'<!-- Stacey('.Stacey::$version.'): '.$this->hash.' -->';
		$fp = fopen($this->cachefile, 'w');
		fwrite($fp, ob_get_contents());
		fclose($fp);
	}

	function create_hash() {
		// create a collection of every file inside the content folder
		$content = $this->collate_files('../content/');
		// create a collection of every file inside the templates folder
		$templates = $this->collate_files('../templates/');
		// create an md5 of the two collections
		return $this->hash = md5($content.$templates);
	}
	
	function collate_files($dir) {
		if(!isset($files_modified)) $files_modified = '';
		foreach(Helpers::list_files($dir, '/.*/') as $file) {
			$files_modified .= $file.':'.filemtime($dir.'/'.$file);
			if(is_dir($dir.'/'.$file)) $this->collate_files($dir.'/'.$file);
		}
		return $files_modified;
	}
	
}


Class Renderer {
	
	var $page;
	
	function __construct($get) {
		// take the passed url ($get) and turn it into an object
		$this->page = $this->handle_routes($get);
	}
	
	function handle_routes($get) {
		// if key is empty, we're looking for the index page
		if(key($get) == '') {
			// creating a new page without passing through a name creates the index page
			return new Page();
		}
		// if key does contain slashes, it must be a category/page
		else if(preg_match('/\//', key($get))) {
			// explode key, [0] => category, [1] => name
			$path = explode('/', key($get));
			// if key contains more than one /, return a 404 as the app doesn't handle more than 2 levels of depth
			if(count($path) > 2) return key($get);
			else return new PageInCategory($path[1], $path[0]);
		}
		// if key contains no slashes, it must be a page or a category
		else {
			// check whether we're looking for a category or a page
			if(Helpers::is_category(key($get))) return new Category(key($get));
			else return new Page(key($get));
		}
	}
	
	function render_404() {
		// return correct 404 header
		header('HTTP/1.0 404 Not Found');
		// if there is a 404 page set, use it
		if(file_exists('../public/404.html')) echo file_get_contents('../public/404.html');
		// otherwise, use this text as a default
		else echo '<h1>404</h1><h2>Page could not be found.</h2><p>Unfortunately, the page you were looking for does not exist here.</p>';
	}
	
	function render() {
		// if page doesn't contain a content file or have a matching template file, redirect to it or return 404
		if(!$this->page || !$this->page->template_file) {
			// if a static html page with a name matching the current route exists in the public folder, serve it 
			if($this->page->public_file) echo file_get_contents($this->page->public_file);
			// serve 404
			else $this->render_404();
		} else {
			// create new cache object
			$cache = new Cache($this->page);
			// check etags
			header ('Etag: "'.$cache->hash.'"');
			if (isset($_SERVER['HTTP_IF_NONE_MATCH']) && stripslashes($_SERVER['HTTP_IF_NONE_MATCH']) == '"'.$cache->hash.'"') {
				// local cache is still fresh, so return 304
				header ("HTTP/1.0 304 Not Modified");
				header ('Content-Length: 0');
			} else {
				// check if cache needs to be expired
				if($cache->check_expired()) {
					// start output buffer
					ob_start();
						// render page
						$t = new TemplateParser;
						$c = new ContentParser;
						echo $t->parse($this->page, $c->parse($this->page));
						// cache folder is writable, write to it
						if(is_writable('./cache')) $cache->write_cache();
						else echo "\n".'<!-- Stacey('.Stacey::$version.'). -->';
					// end buffer
					ob_end_flush();
				} else {
					// else cache hasn't expired, so use existing cache
					echo file_get_contents($cache->cachefile)."\n".'<!-- Cached. -->';
				}
			}
		}
	}
}

Class Page {
	var $name;
	var $name_unclean;

	var $category;
	var $category_unclean;
	
	var $content_file;
	var $template_file;
	var $public_file;
	
	var $i;
	var $unclean_names = array();
	var $image_files = array();
	var $video_files = array();
	var $html_files = array();
	var $swf_files = array();
	var $sibling_pages;
	
	var $link_path;
	var $content_path;
	
	var $default_template = 'content';
	
	function __construct($name = 'index', $category = '') {
		$this->category = $category;
		$this->category_unclean = $this->unclean_name($this->category,'../content/');
		$this->content_path = ($category == '') ? '../content/' : '../content/'.$this->category_unclean.'/';
		
		$this->name = $name;
		$this->name_unclean = $this->unclean_name($this->name, $this->content_path);
		$this->unclean_names = Helpers::list_files($this->content_path, '/.*/', true);
		
		$this->content_file = $this->get_content_file();
		$this->template_file = $this->get_template_file($this->default_template);
		$this->public_file = $this->get_public_file();
		$this->image_files = $this->get_assets('/\.(gif|jpg|png|jpeg)/i');
		$this->video_files = $this->get_assets('/\.(mov|mp4)/i');
		$this->html_files = $this->get_assets('/\.(html|htm)/i');
		$this->swf_files = $this->get_assets('/\.swf/i');
		$this->link_path = $this->construct_link_path();
		
		$this->sibling_pages = $this->get_sibling_pages();
	}
	
	function construct_link_path() {
		$link_path = '';
		if(!preg_match('/index/', $this->content_file)) {
			$link_path .= '../';
			preg_match_all('/\//', $this->content_file, $slashes);
			for($i = 3; $i < count($slashes[0]); $i++) $link_path .= '../';
		}
		return $link_path;
	}
	
	function clean_name($name) {
		// strip leading digit and dot from filename (1.xx becomes xx)
		return preg_replace('/^\d+?\./', '', $name);
	}
	
	function unclean_name($name, $dir) {
		// loop through each unclean page name looking for a match for $name
		foreach(Helpers::list_files($dir, '/.*/', true) as $key => $file) {
			if(preg_match('/'.$name.'$/', $file)) {
				// store current number of this page
				$this->i = ($key + 1);
				// return match
				return $file;
			}
		}
		return false;
	}
	
	function get_assets($regex = '/.*/') {
		// get containing directory by stripping the content file path
		$dir = preg_replace('/\/[^\/]+$/', '', $this->content_file);
		// store a list of all image files
		$files = Helpers::list_files($dir, $regex);
		// remove any thumbnails from the array
		foreach($files as $key => $file) if(preg_match('/thumb\./i', $file)) unset($files[$key]);
		return $files;
	}
	
	function get_template_file($default_template) {
		// check folder exists, if not, return 404
		if(!$this->name_unclean) return false;
		// find the name of the text file
		preg_match('/\/([^\/]+?)\.txt/', $this->content_file, $template_name);
		// if template exists, return it
		if(!empty($template_name) && file_exists('../templates/'.$template_name[1].'.html')) return '../templates/'.$template_name[1].'.html';
		// return content.html as default template (if it exists)
		elseif(file_exists('../templates/'.$default_template.'.html')) return '../templates/'.$default_template.'.html';
		else return false;
	}

	function get_content_file() {
		// check folder exists
		if($this->name_unclean && file_exists($this->content_path.$this->name_unclean)) {
			// look for a .txt file
			$txts = Helpers::list_files($this->content_path.$this->name_unclean, '/\.txt$/');
			// if $txts contains a result, return it
			if(!empty($txts)) return $this->content_path.$this->name_unclean.'/'.$txts[0];
		}
		// return if we didnt find anything
		return $this->content_path.$this->name_unclean.'/none';
	}
	
	function get_public_file() {
		// see if a static html file with $name exists in the public folder
		if(file_exists('../public/'.$this->name.'.html')) return '../public/'.$this->name.'.html';
		else return false;
	}
	
	function get_sibling_pages() {
		// if current page is a MockPageInCategory, escape this function (to prevent infinite loop)
		if(get_class($this) == 'MockPageInCategory') return array(array(), array());
		// loop through each unclean name looking for a match
		foreach($this->unclean_names as $key => $name) {
			// if match found...
			if($name == $this->name_unclean) {
				// store the names of the next/previous pages
				$previous_name = ($key >= 1) ? $this->unclean_names[$key-1] : $this->unclean_names[(count($this->unclean_names)-1)];
				$next_name = ($key + 1 < count($this->unclean_names)) ? $this->unclean_names[$key+1] : $this->unclean_names[0];
				//store the urls of the next/previous pages
				$previous = array('/@url/' => '../'.$this->clean_name($previous_name));
				$next = array('/@url/' => '../'.$this->clean_name($next_name));
				// create MockPageInCategory objects so we can access the variables of the pages
				$previous_page = new MockPageInCategory($this->category, $previous_name);
				$next_page = new MockPageInCategory($this->category, $next_name);
				 
				$c = new ContentParser;
				return array(
					array_merge($previous, $c->parse($previous_page)),
					array_merge($next, $c->parse($next_page)),
				);
				// kill loop
				break;
			}
		}
		
		return array(array(), array());
	}
	
}

Class Category extends Page {
	var $default_template = 'category';
}

Class PageInCategory extends Page {
	var $default_template = 'page-in-category';
}

Class MockPageInCategory extends PageInCategory {
	
	function __construct($category, $folder_name) {
		$this->category_unclean = $this->unclean_name($category, '../content/');
		$this->content_path = '../content/'.$this->category_unclean.'/';
		
		$this->name_unclean = $this->unclean_name(preg_replace('/^\d+?\./', '', $folder_name), '../content/'.$this->category_unclean);
		
		$this->content_file = $this->get_content_file();
		//$this->image_files = $this->get_images(preg_replace('/\/[^\/]+$/', '', $this->content_file)); 
		$this->link_path = $this->construct_link_path();
	}
	
}

Class ContentParser {
	
	var $page;
	
	function preparse($text) {
		$patterns = array(
			// replace inline colons
			'/(?<=\n)([a-z0-9_-]+?):(?!\/)/',
			'/:/',
			'/\\\x01/',
			// replace inline dashes
			'/(?<=\n)-/',
			'/-/',
			'/\\\x02/',
			// automatically link http:// websites
			'/(?<![">])\bhttp&#58;\/\/([\S]+\.[\S]*\.?[A-Za-z0-9]{2,4})/',
			// automatically link email addresses
			'/(?<![;>])\b([A-Za-z0-9.-]+)@([A-Za-z0-9.-]+\.[A-Za-z]{2,4})/',
			// convert lists
			'/\n?-(.+?)(?=\n)/',
			'/(<li>.*<\/li>)/',
			// replace doubled lis
			'/<\/li><\/li>/',
			// replace headings h1. h2. etc
			'/h([0-5])\.\s?(.*)/',
			// wrap multi-line text in paragraphs
			'/([^\n]+?)(?=\n)/',
			'/<p>(.+):(.+)<\/p>/',
			'/: (.+)(?=\n<p>)/',
			// replace any keys that got wrapped in ps
			'/(<p>)([a-z0-9_-]+):(<\/p>)/',
			// replace any headings that got wrapped in ps
			'/<p>(<h[0-5]>.*<\/h[0-5]>)<\/p>/'
		);
		$replacements = array(
			// replace inline colons
			'$1\\x01',
			'&#58;',
			':',
			// replace inline dashes
			'\\x02',
			'&#45;',
			'-',
			// automatically link http:// websites
			'<a href="http&#58;//$1">http&#58;//$1</a>',
			// automatically link email addresses
			'<a href="mailto&#58;$1&#64;$2">$1&#64;$2</a>',
			// convert lists
			'<li>$1</li>',
			'<ul>$1</ul>',
			// replace doubled lis
			'</li>',
			// replace headings h1. h2. etc
			'<h$1>$2</h$1>',
			// wrap multi-line text in paragraphs
			'<p>$1</p>',
			'$1:$2',
			':<p>$1</p>',
			// replace any keys that got wrapped in ps
			'$2:',
			'$1'
		);
		$parsed_text = preg_replace($patterns, $replacements, $text);
		return $parsed_text;
	}
	
	function create_replacement_rules($text) {
		$np = new NextPagePartial;
		$pp = new PreviousPagePartial;
		
		// push additional useful values to the replacement pairs
		$replacement_pairs = array(
			'/@Images_Count/' => count($this->page->image_files),
			'/@Pages_Count/' => count($this->page->unclean_names),
			'/@Page_Number/' => $this->page->i,
			'/@Year/' => date('Y'),
			'/@Site_Root\/?/' =>  $this->page->link_path,
			'/@Previous_Page/' => $pp->render($this->page->sibling_pages[0]),
			'/@Next_Page/' => $np->render($this->page->sibling_pages[1])
		);
		// if the page is a Category, push category-specific variables
		if(get_class($this->page) == 'Category') {
			$c = new CategoryListPartial;
			// look for a partial file matching the categories name, otherwise fall back to using the category partial
			$partial_file = file_exists('../templates/partials/'.$this->page->name.'.html') ? '../templates/partials/'.$this->page->name.'.html' : '../templates/partials/category-list.html';
			// create a dynamic category list variable
			$replacement_pairs['/@Category_List/'] = $c->render($this->page, $this->page->name_unclean, $partial_file);
		}
		
		// pull out each key/value pair from the content file
		preg_match_all('/[\w\d_-]+?:[\S\s]*?\n\n/', $text, $matches);
		foreach($matches[0] as $match) {
			$colon_split = explode(':', $match);
			$replacement_pairs['/@'.$colon_split[0].'/'] = trim($colon_split[1]);
		}
		// sort keys by length, to ensure replacements are made in the correct order (ie. @page does not partially replace @page_name)
		uksort($replacement_pairs, array('Helpers', 'sort_by_length'));
		return $replacement_pairs;
	}
	
	function parse($page) {
		// store page and parse its content file
		$this->page = $page;
		// store contents of content file (if it exists, otherwise, pass back an empty string)
		$text = (file_exists($this->page->content_file)) ? file_get_contents($this->page->content_file) : '';
		// include shared variables for each page
		$shared = (file_exists('../content/_shared.txt')) ? file_get_contents('../content/_shared.txt') : '';
		// run preparsing rules to clean up content files (the newlines are added to ensure the first and last rules have their double-newlines to match on)
		$parsed_text = $this->preparse("\n\n".$text."\n\n".$shared."\n\n");
		// create the replacement rules
		return $this->create_replacement_rules($parsed_text);
	}
	
}

Class TemplateParser {

	var $page;
	var $replacement_pairs;
	
	function find_categories() {
		$dir = '../content/';
		$categories = array();
	
		// loop through each top-level folder to check if it contains other folders (in which case it is a category);
		foreach(Helpers::list_files($dir, '/.*/', true) as $folder) {
				$inner_folders = Helpers::list_files('../content/'.$folder, '/.*/', true);
				if(!empty($inner_folders)) {
					// strip leading digit and dot from filename (1.xx becomes xx)
					$folder_clean = preg_replace('/^\d+?\./', '', $folder);
					$categories[$folder] = array(
						'name' => $folder,
						'name_clean' => $folder_clean,
						// look for a partial file matching the categories name, otherwise fall back to using the category partial
						'partial_file' => file_exists('../templates/partials/'.$folder_clean.'.html') ? '../templates/partials/'.$file_clean.'.html' : '../templates/partials/category-list.html'
					);
				}
		}
		return $categories;
	}
	
	function create_replacement_partials() {
		// constructs a partial for each category within the content folder
		$c = new CategoryListPartial;
		// constructs a partial containing each image on the page
		$i = new ImagesPartial;
		// constructs a partial containing all of the top level pages & categories, excluding the index
		$n = new NavigationPartial;
		// constructs a partial containing all of the top level pages, excluding any categories and the index
		$p = new PagesPartial;
		
		// construct a special variable which will hold all of the category lists
		$partials['/@Category_Lists/'] = '';
		// find all categories
		$categories = $this->find_categories();
		// category lists will become available as a variable as: '$.projects-folder' => @Projects_Folder
		foreach($categories as $category) {
			// store the output of the CategoryListPartial
			$category_list = $c->render($this->page, $category['name'], $category['partial_file']);
			// create a partial that matches the name of the category
			$partials['/@'.ucfirst(preg_replace('/-(.)/e', "'_'.strtoupper('\\1')", $category['name_clean'])).'/'] = $category_list;
			// append to the @Category_Lists variable
			$partials['/@Category_Lists/'] .= $category_list;
		}
		// construct the rest of the special variables
		$partials['/@Images/'] = $i->render($this->page);
		$partials['/@Navigation/'] = $n->render($this->page);
		$partials['/@Pages/'] = $p->render($this->page);
		return $partials;
	}
	
	function parse($page, $rules) {
		// store reference to current page
		$this->page = $page;
		// create all the replacement pairs that rely on partials
		$this->replacement_pairs = array_merge($rules, $this->create_replacement_partials());
		// sort keys by length, to ensure replacements are made in the correct order (ie. @page does not partially replace @page_name)
		uksort($this->replacement_pairs, array('Helpers', 'sort_by_length'));
		// store template file content
		$text = file_get_contents($this->page->template_file);
		// run replacements on the template
		return preg_replace(array_keys($this->replacement_pairs), array_values($this->replacement_pairs), $text);
	}
}

Class Partial {
	
	var $page;
	var $partial_file;
	
	function check_thumb($dir, $file) {
		$thumbs = Helpers::list_files($dir.'/'.$file, '/thumb\.(gif|jpg|png|jpeg)/i');
		return (!empty($thumbs)) ? $dir.'/'.$file.'/'.$thumbs[0]  : '';
	}

	function get_partial() {
		$partial = (file_exists($this->partial_file)) ? file_get_contents($this->partial_file) : '<p>! '.$this->partial_file.' not found.</p>';
		// split the template file by loop code
		preg_match('/([\S\s]*)foreach[\S\s]*?:([\S\s]*)endforeach;([\S\s]*)/', $partial, $matches);
		// if partial file found, return array containing the markup: before loop, inside loop & after loop (in that order)
		if(!empty($matches)) return array($matches[1], $matches[2], $matches[3]);
		// if partial file not found, return warning string
		else return array($partial, '', '');
	}
	
}

Class CategoryListPartial extends Partial {
	
	var $dir;

	function render($page, $dir, $partial_file) {
		// store reference to current page
		$this->page = $page;
		// store correct partial file
		$this->partial_file = $partial_file;
		$this->dir = '../content/'.$dir;
		// pull out html wrappers from partial file
		$wrappers = $this->get_partial();
		$html = '';
		
		// add opening outer wrapper
		$html .= $wrappers[0];
		
		$files = Helpers::list_files($this->dir, '/^\d+?\./', true);
		foreach($files as $key => $file) {
			// for each page within this category...
			$vars = array(
				'/@url/' => $this->page->link_path.preg_replace('/^\d+?\./', '', $dir).'/'.preg_replace('/^\d+?\./', '', $file).'/',
				'/@thumb/' => $this->check_thumb($this->dir, $file)
			);
			// create a MockPageInCategory to give us access to all the variables inside this PageInCategory
			$c = new ContentParser;
			$category_page = new MockPageInCategory($dir, $file);
			$vars = array_merge($vars, $c->parse($category_page));
			$html .= preg_replace(array_keys($vars), array_values($vars), $wrappers[1]);			
		}
		// add closing outer wrapper
		$html .= $wrappers[2];
		return $html;
	}
	
}

Class NavigationPartial extends Partial {
	
	var $dir = '../content/';
	var $partial_file = '../templates/partials/navigation.html';

	function render($page) {
		// store reference to current page
		$this->page = $page;
		$html = '';
		// pull out html wrappers from partial file
		$wrappers = $this->get_partial();
		
		// add opening outer wrapper
		$html .= $wrappers[0];
		
		// collate navigation set
		$files = Helpers::list_files($this->dir, '/^\d+?\./', true);
		foreach($files as $key => $file) {
			// if file is not the index, add it to the navigation list
			if (!preg_match('/index/', $file)) {
				$file_name_clean = preg_replace('/^\d+?\./', '', $file);
				// store the url and name of the navigation item
				$replacements = array(
					'/@url/' => $this->page->link_path.$file_name_clean.'/',
					'/@name/' => ucfirst(preg_replace('/-/', ' ', $file_name_clean)),
				);

				$html .= preg_replace(array_keys($replacements), array_values($replacements), $wrappers[1]);
			}
		}
		// add closing outer wrapper
		$html .= $wrappers[2];
		
		return $html;
	}
	
}

Class PagesPartial extends Partial {
	
	var $dir = '../content';
	var $partial_file = '../templates/partials/pages.html';

	function render($page) {
		// store reference to current page
		$this->page = $page;
		$html = '';
		// pull out html wrappers from partial file
		$wrappers = $this->get_partial();
		// add opening outer wrapper
		$html .= $wrappers[0];
		
		// collate navigation set
		$files = Helpers::list_files($this->dir, '/^\d+?\./', true);
		foreach($files as $key => $file) {
			// if file is not a category and is not the index page, add it to the pages list
			if (!preg_match('/index/', $file) && !Helpers::is_category($file, $this->dir)) {
				$file_name_clean = preg_replace('/^\d+?\./', '', $file);
				// store the url and name of the navigation item
				$replacements = array(
					'/@url/' => $this->page->link_path.$file_name_clean.'/',
					'/@name/' => ucfirst(preg_replace('/-/', ' ', $file_name_clean)),
				);

				$html .= preg_replace(array_keys($replacements), array_values($replacements), $wrappers[1]);
			}
		}
		// add closing outer wrapper
		$html .= $wrappers[2];
		
		return $html;

		
		return $html;
	}
	
}

Class ImagesPartial extends Partial {

	var $dir;
	var $partial_file = '../templates/partials/images.html';

	function render($page) {
		// store reference to current page
		$this->page = $page;
		
		// strip out the name of the content file (ie content.txt) and create the path to the folder
		$dir = preg_replace('/\/[^\/]+$/', '', $this->page->content_file);
		$dir = $this->page->link_path.preg_replace('/\.\.\//', '', $dir);
		
		$html = '';
		// pull out html wrappers from partial file
		$wrappers = $this->get_partial();
		$files = $this->page->image_files;
		
		// add opening outer wrapper
		$html .= $wrappers[0];
		// loop through inner wrapper, replacing any variables contained within
		foreach($files as $key => $file) {
			$html .= preg_replace('/@url/', $dir.'/'.$file, $wrappers[1]);
		}
		// add closing outer wrapper
		$html .= $wrappers[2];
		return $html;
	}

}

Class NextPagePartial extends Partial {
	var $partial_file = '../templates/partials/next-page.html';
	
	function render($page_sibling) {
		$partial = (file_exists($this->partial_file)) ? file_get_contents($this->partial_file) : '';
		// replace html with @vars
		$html = (!empty($page_sibling)) ? preg_replace(array_keys($page_sibling), array_values($page_sibling), $partial) : '';
		return $html;
	}
}

Class PreviousPagePartial extends NextPagePartial {
	var $partial_file = '../templates/partials/previous-page.html';
}

?>