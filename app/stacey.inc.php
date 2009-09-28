<?php

class Stacey {
	function __construct($get) {
		if(function_exists('date_default_timezone_set')) date_default_timezone_set('Australia/Melbourne');
		$r = new Renderer($get);
		$r->render();
	}
}

class Renderer {
	
	var $page;
	
	function __construct($get) {
		$this->page = (array_key_exists('name', $get)) ? new Project($get['name']) : new Page(key($get));
	}
	
	function render_404() {
		header('HTTP/1.0 404 Not Found');
		echo file_get_contents('../public/404.html');
	}
	
	function handle_redirects() {
		// if page does not end in a trailing slash, add one
		if(preg_match('/index\/?$/', $_SERVER['REQUEST_URI'])) {
			header('HTTP/1.1 301 Moved Permanently');
			header('Location: ../');
			return true;
		}
		
		if(!preg_match('/\/$/', $_SERVER['REQUEST_URI'])) {
			header('HTTP/1.1 301 Moved Permanently');
			header('Location: ' . $_SERVER['REQUEST_URI'] . '/');
			return true;
		}
		
		// if they're looking for /projects/, redirect them to the index page
		if(preg_match('/projects\/$/', $_SERVER['REQUEST_URI'])) {
			header('HTTP/1.1 301 Moved Permanently');
			header('Location: ../');
			return true;
		}
		
		return false;
	}
	
	function render() {
		
		if($this->handle_redirects()) return;
		
		if(!$this->page->content_file || !$this->page->template_file) {
			if($this->page->public_file) echo file_get_contents($this->page->public_file);
			else $this->render_404();
		} else {
			$cache = new Cache($this->page);
			if($cache->check_expired()) {
				$t = new TemplateParser;
				$c = new ContentParser;
				ob_start();
					echo $t->parse($this->page, $c->parse($this->page));
					if(is_writable('./cache')) $cache->write_cache();
				ob_end_flush();
			} else {
				include($cache->cachefile);
				echo "\n" . '<!-- Cached. -->';
			}
		}
	}
}


class Cache {

	var $page;
	var $cachefile;
	
	function __construct($page) {
		$this->page = $page;
		$this->cachefile = './cache/' . base64_encode($this->page->content_file);
	}
	
	function check_expired() {
		if(!file_exists($this->cachefile)) return true;
		elseif(filemtime($this->page->content_file) > filemtime($this->cachefile)) return true;
		elseif(filemtime($this->page->template_file) > filemtime($this->cachefile)) return true;
		elseif(filemtime('../templates/partials/images.html') > filemtime($this->cachefile)) return true;
		elseif(filemtime('../templates/partials/projects.html') > filemtime($this->cachefile)) return true;
		elseif(filemtime('../templates/partials/navigation.html') > filemtime($this->cachefile)) return true;
		elseif(filemtime('../templates/partials/previous-project.html') > filemtime($this->cachefile)) return true;
		elseif(filemtime('../templates/partials/next-project.html') > filemtime($this->cachefile)) return true;
		elseif($this->create_hash() !== $this->get_current_hash()) return true;
		else return false;
	}
	
	function write_cache() {
		echo "\n" . '<!-- Cache: ' . $this->create_hash(preg_replace('/\/[^\/]+$/', '', $this->page->content_file)) . ' -->';
		$fp = fopen($this->cachefile, 'w');
		fwrite($fp, ob_get_contents());
		fclose($fp);
	}

	function create_hash() {
		$images = $this->collate_images(preg_replace('/\/[^\/]+$/', '', $this->page->content_file));
		$content = $this->collate_files('../content/');
		$projects = $this->collate_projects();
		return md5($images.$content.$projects);
	}
	
	function collate_files($dir) {
		$files_modified = '';
		if(is_dir($dir)) {
			if($dh = opendir($dir)) {
				while (($file = readdir($dh)) !== false) {
					if(!is_dir($file)) $files_modified .= $file.':'.filemtime($dir.'/'.$file);
				}
			}
		}
		return $files_modified;
	}
	
	function collate_projects() {
		$projects_modified = '';
		$dir = '../content/'.$this->page->projects_folder_unclean;
		if($dh = opendir($dir)) {
			while (($file = readdir($dh)) !== false) {
				if(!is_dir($file)) {
					$projects_modified .= $file.':'.filemtime($dir.'/'.$file);
					if(is_dir($dir.'/'.$file)){
						if($idh = opendir($dir.'/'.$file)) {
							while (($inner_file = readdir($idh)) !== false) {
								$projects_modified .= $inner_file.':'.filemtime($dir.'/'.$file.'/'.$inner_file);
							}
						}
					}
				}
			}
		}
		return $projects_modified;
	}
	
	function collate_images($dir) {
		$images_modified = '';
		if(is_dir($dir)) {
			if($dh = opendir($dir)) {
				while (($file = readdir($dh)) !== false) {
					if(!is_dir($file)) {
						if(!is_dir($file) && !preg_match('/thumb\.[gif|jpg|png|jpeg]/i', $file)) {
							$images_modified .= $file.':'.filemtime($dir.'/'.$file);
						} 
					}
				}
			}
		}
		return $images_modified;
	}
	
	function get_current_hash() {
		preg_match('/Cache: (.+?)\s/', file_get_contents($this->cachefile), $matches);
		return $matches[1];
	}
	
}

class Page {
	
	var $page_name;
	var $content_file;
	var $template_file;
	var $public_file;
	var $image_files = array();
	
	var $page_name_unclean;
	var $page_number;
	var $projects_folder_unclean;
	var $unclean_page_names = array();
	var $link_path;
	
	function __construct($page_name) {
		$this->page = ($page_name) ? $page_name : 'index';
		$this->store_unclean_page_names('../content/');
		$this->page_name_unclean = $this->unclean_page_name($this->page);
		$this->projects_folder_unclean = $this->unclean_page_name('projects');
		$this->store_unclean_page_names('../content/'.$this->projects_folder_unclean);
		
		$this->template_file = $this->get_template_file();
		$this->content_file = $this->get_content_file();
		$this->public_file = $this->get_public_file();
		$this->image_files = $this->get_images(preg_replace('/\/[^\/]+$/', '', $this->content_file));
		$this->link_path = $this->construct_link_path();
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
	
	function store_unclean_page_names($dir) {
		$this->unclean_page_names = $this->list_files($dir, '/^(?<!\.)[\w\d-]+/');
	}

	function list_files($dir, $regex) {
		if(!is_dir($dir)) return false;
		if(!$dh = opendir($dir)) return false;
		$files = null;
		while (($file = readdir($dh)) !== false) if(!is_dir($file) && preg_match($regex, $file)) $files[] = $file;
		closedir($dh);
		sort($files, SORT_NUMERIC);
		return $files;
	}
	
	function clean_page_name($page_name) {
		return preg_replace('/^\d+?\./', '', $page_name);
	}

	function unclean_page_name($name) {
		foreach($this->unclean_page_names as $key => $file) {
			if(preg_match('/' . $name . '(\.txt)?$/', $file)) {
				$this->page_number = ($key + 1);
				return $file;
			}
		}
		return false;
	}
	
	function get_images($dir) {
		$image_files = null;
		if(is_dir($dir)) {
		 	if($dh = opendir($dir)) {
		 		while (($file = readdir($dh)) !== false) {
		 			if(!is_dir($file) && preg_match('/\.(gif|jpg|png|jpeg)/i', $file) && !preg_match('/thumb\./i', $file)) {
						$image_files[] = $file;
					}
				}
			}
			closedir($dh);
		}
		return $image_files;
	}
	
	function get_content_file() {
		if($this->page_name_unclean && file_exists('../content/$this->page_name_unclean/content.txt')) return '../content/$this->page_name_unclean/content.txt';
		elseif($this->page_name_unclean && file_exists('../content/'.$this->page_name_unclean)) return '../content/'.$this->page_name_unclean;
		else return false;
	}
	
	function get_template_file() {
		if(file_exists('../templates/$this->page.html')) return '../templates/$this->page.html';
		elseif(file_exists('../templates/content.html')) return '../templates/content.html';
		else return false;
	}
	
	function get_public_file() {
		if(file_exists('../public/' . $this->page . '.html')) return '../public/' . $this->page . '.html';
		else return false;
	}

	
}

class Project extends Page {
	
	var $sibling_projects;
	
	function __construct($page_name) {
		$this->page = ($page_name) ? $page_name : 'index';
		$this->store_unclean_page_names('../content/');
		$this->projects_folder_unclean = $this->unclean_page_name('projects');
		$this->store_unclean_page_names('../content/'.$this->projects_folder_unclean);
		$this->page_name_unclean = $this->unclean_page_name($this->page);
		$this->sibling_projects = $this->get_sibling_projects();
		
		$this->template_file = $this->get_template_file();
		$this->content_file = $this->get_content_file();
		$this->public_file = $this->get_public_file();
		$this->image_files = $this->get_images(preg_replace('/\/[^\/]+$/', '', $this->content_file));
		$this->link_path = $this->construct_link_path();
	}
	
	function get_content_file() {
		if($this->page_name_unclean && file_exists('../content/'.$this->projects_folder_unclean.'/' . $this->page_name_unclean . '/content.txt')) return '../content/'.$this->projects_folder_unclean . '/' . $this->page_name_unclean . '/content.txt';
		else return false;
	}
	
	function get_template_file() {
		if(file_exists('../templates/project.html')) return '../templates/project.html';
		else return false;
	}
	
	function get_sibling_projects() {
		if(get_class($this) == 'MockProject') return array(array(), array());
		
		foreach($this->unclean_page_names as $key => $page_name) {
			if($page_name == $this->page_name_unclean) {
				$previous_project_name = ($key >= 1) ? $this->unclean_page_names[$key-1] : $this->unclean_page_names[(count($this->unclean_page_names)-1)];
				$next_project_name = ($key + 1 < count($this->unclean_page_names)) ? $this->unclean_page_names[$key+1] : $this->unclean_page_names[0];

				$previous_project = array('/@url/' => '../'.$this->clean_page_name($previous_project_name));
				$next_project = array('/@url/' => '../'.$this->clean_page_name($next_project_name));

				$previous_project_page = new MockProject($previous_project_name);
				$next_project_page = new MockProject($next_project_name);
				
				$c = new ContentParser;
				return array(
					array_merge($previous_project, $c->parse($previous_project_page)),
					array_merge($next_project, $c->parse($next_project_page)),
				);
			}
		}
		
		return array(array(), array());
	}
}

class MockProject extends Project {
	
	var $folder_name;
	
	function __construct($folder_name) {
		$this->folder_name = $folder_name;
		$this->store_unclean_page_names('../content/');
		$this->projects_folder_unclean = $this->unclean_page_name('projects');
		$this->store_unclean_page_names('../content/'.$this->projects_folder_unclean);
		$this->page_name_unclean = $this->unclean_page_name(preg_replace('/^\d+/', '', $folder_name));
		$this->sibling_projects = $this->get_sibling_projects();
		
		$this->content_file = $this->get_content_file();
		$this->image_files = $this->get_images(preg_replace('/\/[^\/]+$/', '', $this->content_file)); 
		$this->link_path = $this->construct_link_path();
	}

	function get_content_file() {
		if(file_exists('../content/'.$this->projects_folder_unclean.'/'.$this->folder_name.'/content.txt')) return '../content/'.$this->projects_folder_unclean.'/'.$this->folder_name.'/content.txt';
		else return false;
	}
	
}

class ContentParser {
	
	var $page;
	
	static function sort_by_length($a,$b){
		if($a == $b) return 0;
		return (strlen($a) < strlen($b) ? -1 : 1);
	}
	
	function preparse($text) {
		$patterns = array(
			# replace inline colons
			'/(?<=\n)([a-z0-9_-]+?):(?!\/)/',
			'/:/',
			'/\\\x01/',
			# replace inline dashes
			'/(?<=\n)-/',
			'/-/',
			'/\\\x02/',
			# automatically link http:// websites
			'/(?<![">])\bhttp&#58;\/\/([\S]+\.[\S]*\.?[A-Za-z0-9]{2,4})/',
			# automatically link email addresses
			'/(?<![;>])\b([A-Za-z0-9.-]+)@([A-Za-z0-9.-]+\.[A-Za-z]{2,4})/',
			# convert lists
			'/\n?-(.+?)(?=\n)/',
			'/(<li>.*<\/li>)/',
			# replace doubled lis
			'/<\/li><\/li>/',
			# wrap multi-line text in paragraphs
			'/([^\n]+?)(?=\n)/',
			'/<p>(.+):(.+)<\/p>/',
			'/: (.+)(?=\n<p>)/',
			# replace any keys that got wrapped in ps
			'/(<p>)([a-z0-9_-]+):(<\/p>)/',
		);
		$replacements = array(
			# replace inline colons
			'$1\\x01',
			'&#58;',
			':',
			# replace inline dashes
			'\\x02',
			'&#45;',
			'-',
			# automatically link http:// websites
			'<a href="http&#58;//$1">http&#58;//$1</a>',
			# automatically link email addresses
			'<a href="mailto&#58;$1&#64;$2">$1&#64;$2</a>',
			# convert lists
			'<li>$1</li>',
			'<ul>$1</ul>',
			# replace doubled lis
			'</li>',
			# wrap multi-line text in paragraphs
			'<p>$1</p>',
			'$1:$2',
			':<p>$1</p>',
			# replace any keys that got wrapped in ps
			'$2:',
		);
		$parsed_text = preg_replace($patterns, $replacements, $text);
		return $parsed_text;
	}
	
	function create_replacement_rules($text) {
		// additional, useful values
		$replacement_pairs = array(
			'/@Images_Count/' => count($this->page->image_files),
			'/@Projects_Count/' => count($this->page->unclean_page_names),
		);
		
		if(isset($this->page->sibling_projects)) {
			$np = new NextProjectPartial;
			$pp = new PreviousProjectPartial;
			$replacement_pairs['/@Project_Number/'] = $this->page->page_number;
			$replacement_pairs['/@Previous_Project/'] = $pp->render($this->page->sibling_projects[0]);
			$replacement_pairs['/@Next_Project/'] = $np->render($this->page->sibling_projects[1]);
		}
		
		preg_match_all('/[\w\d_-]+?:[\S\s]*?\n\n/', $text, $matches);
		foreach($matches[0] as $match) {
			$colon_split = explode(':', $match);
			$replacement_pairs['/@'.$colon_split[0].'/'] = trim($colon_split[1]);
		}
		
		// sort keys by length, to ensure replacements are made in the correct order
		uksort($replacement_pairs, array('ContentParser', 'sort_by_length'));
		return $replacement_pairs;
	}
	
	function parse($page) {
		$this->page = $page;
		$text = file_get_contents($this->page->content_file);
		$shared = (file_exists('../content/_shared.txt')) ? file_get_contents('../content/_shared.txt') : '';
		$parsed_text = $this->preparse("\n\n".$text."\n\n".$shared."\n\n");
		return $this->create_replacement_rules($parsed_text);
	}
	
}

class TemplateParser {

	var $page;
	var $matches = array('/@Projects/', '/@Images/', '/@Navigation/', '/@Year/');
	var $replacements;
	
	function create_replacement_partials() {
		$p = new ProjectsPartial;
		$i = new ImagesPartial;
		$n = new NavigationPartial;
		$partials[] = $p->render($this->page);
		$partials[] = $i->render($this->page);
		$partials[] = $n->render($this->page);
		$partials[] = date('Y');
		return $partials;
	}
	
	function add_replacement_rules($rules) {
		foreach($rules as $key => $value) {
			array_unshift($this->matches, $key);
			array_unshift($this->replacements, $value);
		}
	}
	
	function parse($page, $rules) {
		$this->page = $page;
		$this->replacements = $this->create_replacement_partials();
		$text = file_get_contents($this->page->template_file);
		$this->add_replacement_rules($rules);
		return preg_replace($this->matches, $this->replacements, $text);
	}
}

class Partial {
	
	var $page;
	
	function check_thumb($dir, $file) {
		$file_types = array('jpg', 'gif', 'png', 'jpeg', 'JPG', 'GIF', 'PNG', 'JPEG');
		foreach($file_types as $file_type) {
			if(file_exists($dir.'/'.$file.'/thumb.'.$file_type)) {
				return preg_replace('/\.\.\//', $this->page->link_path, $dir).'/'.$file.'/thumb.'.$file_type;
			}
		}
		return '';
	}
	
	function parse($file) {
		$file = file_get_contents($file);
		preg_match('/([\S\s]*)foreach[\S\s]*?:([\S\s]*)endforeach;([\S\s]*)/', $file, $matches);
		return array($matches[1], $matches[2], $matches[3]);
	}
	
}

class NavigationPartial extends Partial {

	var $dir = '../content/';
	var $partial_file = '../templates/partials/navigation.html';

	function render($page) {
		$this->page = $page;
		$wrappers = $this->parse($this->partial_file);
		$html = null;
		
		if($dh = opendir($this->dir)) {
			while (($file = readdir($dh)) !== false) {
				if(!is_dir($file) && $file != '.DS_Store' && !preg_match('/index/', $file) && !preg_match('/^_/', $file)) {
					$files[] = $file;
					$file_name_clean = preg_replace(array('/^\d+?\./', '/\.txt/'), '', $file);
					$file_vars[] = array(
						'/@url/' => $this->page->link_path.$file_name_clean.'/',
						'/@name/' => ucfirst(preg_replace('/-/', ' ', $file_name_clean)),
					);
				}
			}
		}
		
		asort($files, SORT_NUMERIC);
		$html .= $wrappers[0];
		#$p = new ProjectsPartial;
		foreach($files as $key => $file) {
			$html .= preg_replace(array_keys($file_vars[$key]), array_values($file_vars[$key]), $wrappers[1]);
			#if(preg_match('/projects$/', $file)) $html .= $p->render($this->page);
		}
		$html .= $wrappers[2];
		
		return $html;
	}
	
}

class ImagesPartial extends Partial {

	var $dir;
	var $partial_file = '../templates/partials/images.html';

	function render($page) {
		
		$this->page = $page;
		$dir = preg_replace('/\/[^\/]+$/', '', $this->page->content_file);
		$html = null;
		$wrappers = $this->parse($this->partial_file);
		
		if(is_dir($dir)) {
		 	if($dh = opendir($dir)) {
				$files = null;
		 		while (($file = readdir($dh)) !== false) {
		 			if(!is_dir($file) && preg_match('/\.(gif|jpg|png|jpeg)/i', $file) && !preg_match('/thumb\./i', $file)) {
						$files[] = $file;
						$file_vars[] = array(
							'/@url/' => $this->page->link_path.preg_replace('/\.\.\//', '', $dir).'/'.$file,
						);
					}
				}
			}
			closedir($dh);
			if(count($files) > 0) {
				asort($files, SORT_NUMERIC);
				$html = $wrappers[0];
				foreach($files as $key => $file) $html .= preg_replace(array_keys($file_vars[$key]), array_values($file_vars[$key]), $wrappers[1]);
				$html .= $wrappers[2];
			}
		}
		return $html;
	}

}

class ProjectsPartial extends Partial {
	
	var $dir;
	var $partial_file = '../templates/partials/projects.html';

	function render($page) {
		$this->page = $page;
		$this->dir = '../content/'.$page->projects_folder_unclean;
		$wrappers = $this->parse($this->partial_file);
		
		if(is_dir($this->dir)) {
		 	if($dh = opendir($this->dir)) {
		 		while (($file = readdir($dh)) !== false) {
		 			if(!is_dir($file) && file_exists($this->dir.'/'.$file.'/content.txt')) {
						$files[] = $file;
						$vars = array(
							'/@url/' => $this->page->link_path.'projects/'.preg_replace('/^\d+?\./', '', $file).'/',
							'/@thumb/' => $this->check_thumb($this->dir, $file)
						);
						$c = new ContentParser;
						$project_page = new MockProject($file);
						$file_vars[] = array_merge($vars, $c->parse($project_page));
					}
				}
			}
			closedir($dh);
			asort($files, SORT_NUMERIC);
			$html = $wrappers[0];
			foreach($files as $key => $file) $html .= preg_replace(array_keys($file_vars[$key]), array_values($file_vars[$key]), $wrappers[1]);
			$html .= $wrappers[2];
		}
		
		return $html;
	}

}

class NextProjectPartial extends Partial {
	var $page;
	var $partial_file = '../templates/partials/next-project.html';
	
	function render($project_sibling) {
		$html = preg_replace(array_keys($project_sibling), array_values($project_sibling), file_get_contents($this->partial_file));
		return $html;
	}
}

class PreviousProjectPartial extends NextProjectPartial {
	var $partial_file = '../templates/partials/previous-project.html';
}

?>