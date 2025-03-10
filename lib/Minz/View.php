<?php
/**
 * MINZ - Copyright 2011 Marien Fressinaud
 * Sous licence AGPL3 <http://www.gnu.org/licenses/>
*/

/**
 * The Minz_View represents a view in the MVC paradigm
 */
class Minz_View {
	const VIEWS_PATH_NAME = '/views';
	const LAYOUT_PATH_NAME = '/layout/';
	const LAYOUT_DEFAULT = 'layout';

	private $view_filename = '';
	private $layout_filename = '';

	private static $base_pathnames = array(APP_PATH);
	private static $title = '';
	private static $styles = array ();
	private static $scripts = array ();
	private static $themeColors;

	private static $params = array ();

	/**
	 * Constructeur
	 * Détermine si on utilise un layout ou non
	 */
	public function __construct() {
		$this->_layout(self::LAYOUT_DEFAULT);
		$conf = Minz_Configuration::get('system');
		self::$title = $conf->title;
	}

	/**
	 * [deprecated] Change the view file based on controller and action.
	 */
	public function change_view($controller_name, $action_name) {
		Minz_Log::warning('Minz_View::change_view is deprecated, it will be removed in a future version. Please use Minz_View::_path instead.');
		$this->_path($controller_name. '/' . $action_name . '.phtml');
	}

	/**
	 * Change the view file based on a pathname relative to VIEWS_PATH_NAME.
	 *
	 * @param string $path the new path
	 */
	public function _path($path) {
		$this->view_filename = self::VIEWS_PATH_NAME . '/' . $path;
	}

	/**
	 * Add a base pathname to search views.
	 *
	 * New pathnames will be added at the beginning of the list.
	 *
	 * @param string $base_pathname the new base pathname.
	 */
	public static function addBasePathname($base_pathname) {
		array_unshift(self::$base_pathnames, $base_pathname);
	}

	/**
	 * Construit la vue
	 */
	public function build () {
		if ($this->layout_filename !== '') {
			$this->buildLayout ();
		} else {
			$this->render ();
		}
	}

	/**
	 * Include a view file.
	 *
	 * The file is searched inside list of $base_pathnames.
	 *
	 * @param string $filename the name of the file to include.
	 * @return boolean true if the file has been included, false else.
	 */
	private function includeFile($filename) {
		// We search the filename in the list of base pathnames. Only the first view
		// found is considered.
		foreach (self::$base_pathnames as $base) {
			$absolute_filename = $base . $filename;
			if (file_exists($absolute_filename)) {
				include $absolute_filename;
				return true;
			}
		}

		return false;
	}

	/**
	 * Construit le layout
	 */
	public function buildLayout () {
		header('Content-Type: text/html; charset=UTF-8');
		if (!$this->includeFile($this->layout_filename)) {
			Minz_Log::notice('File not found: `' . $this->layout_filename . '`');
		}
	}

	/**
	 * Affiche la Vue en elle-même
	 */
	public function render () {
		if (!$this->includeFile($this->view_filename)) {
			Minz_Log::notice('File not found: `' . $this->view_filename . '`');
		}
	}

	public function renderToString(): string {
		ob_start();
		$this->render();
		return ob_get_clean();
	}

	/**
	 * Ajoute un élément du layout
	 * @param string $part l'élément partial à ajouter
	 */
	public function partial ($part) {
		$fic_partial = self::LAYOUT_PATH_NAME . '/' . $part . '.phtml';
		if (!$this->includeFile($fic_partial)) {
			Minz_Log::warning('File not found: `' . $fic_partial . '`');
		}
	}

	/**
	 * Affiche un élément graphique situé dans APP./views/helpers/
	 * @param string $helper l'élément à afficher
	 */
	public function renderHelper ($helper) {
		$fic_helper = '/views/helpers/' . $helper . '.phtml';
		if (!$this->includeFile($fic_helper)) {
			Minz_Log::warning('File not found: `' . $fic_helper . '`');
		}
	}

	/**
	 * Retourne renderHelper() dans une chaîne
	 * @param string $helper l'élément à traîter
	 */
	public function helperToString($helper) {
		ob_start();
		$this->renderHelper($helper);
		return ob_get_clean();
	}

	/**
	 * Choose the current view layout.
	 * @param string|false $layout the layout name to use, false to use no layouts.
	 */
	public function _layout($layout) {
		if ($layout) {
			$this->layout_filename = self::LAYOUT_PATH_NAME . $layout . '.phtml';
		} else {
			$this->layout_filename = '';
		}
	}

	/**
	 * Choose if we want to use the layout or not.
	 * @deprecated Please use the `_layout` function instead.
	 * @param bool $use true if we want to use the layout, false else
	 */
	public function _useLayout ($use) {
		Minz_Log::warning('Minz_View::_useLayout is deprecated, it will be removed in a future version. Please use Minz_View::_layout instead.');
		if ($use) {
			$this->_layout(self::LAYOUT_DEFAULT);
		} else {
			$this->_layout(false);
		}
	}

	/**
	 * Gestion du titre
	 */
	public static function title () {
		return self::$title;
	}
	public static function headTitle () {
		return '<title>' . self::$title . '</title>' . "\n";
	}
	public static function _title ($title) {
		self::$title = $title;
	}
	public static function prependTitle ($title) {
		self::$title = $title . self::$title;
	}
	public static function appendTitle ($title) {
		self::$title = self::$title . $title;
	}

	/**
	 * Gestion des feuilles de style
	 */
	public static function headStyle () {
		$styles = '';

		foreach(self::$styles as $style) {
			$styles .= '<link rel="stylesheet" ' .
				($style['media'] === 'all' ? '' : 'media="' . $style['media'] . '" ') .
				'href="' . $style['url'] . '" />';

			$styles .= "\n";
		}

		return $styles;
	}
	/**
	 * Prepends a <link> element referencing stylesheet.
	 *
	 * @param string $url
	 * @param string $media
	 * @param bool $cond Conditional comment for IE, now deprecated and ignored
	 */
	public static function prependStyle($url, $media = 'all', $cond = false) {
		array_unshift (self::$styles, array (
			'url' => $url,
			'media' => $media,
		));
	}
	/**
	 * Append a `<link>` element referencing stylesheet.
	 * @param string $url
	 * @param string $media
	 * @param bool $cond Conditional comment for IE, now deprecated and ignored
	 */
	public static function appendStyle($url, $media = 'all', $cond = false) {
		self::$styles[] = array (
			'url' => $url,
			'media' => $media,
		);
	}

	/**
	 * @param array|string $themeColors
	 */
	public static function appendThemeColors($themeColors): void {
		self::$themeColors = $themeColors;
	}

	/**
	 * https://developer.mozilla.org/en-US/docs/Web/HTML/Element/meta/name/theme-color
	 */
	public static function metaThemeColor(): string {
		$meta = '';

		if (!empty(self::$themeColors['light'])) {
			$meta .= '<meta name="theme-color" media="(prefers-color-scheme: light)" content="' . htmlspecialchars(self::$themeColors['light']) . '" />';
		}
		if (!empty(self::$themeColors['dark'])) {
			$meta .= '<meta name="theme-color" media="(prefers-color-scheme: dark)" content="' . htmlspecialchars(self::$themeColors['dark']) . '" />';
		}
		if (!empty(self::$themeColors['default'])) {
			$meta .= '<meta name="theme-color" content="' . htmlspecialchars(self::$themeColors['default']) . '" />';
		}
		if (empty(self::$themeColors['default']) && !empty(self::$themeColors) && empty(self::$themeColors['light']) && empty(self::$themeColors['dark'])) {
			$meta .= '<meta name="theme-color" content="' . htmlspecialchars(self::$themeColors) . '" />';
		}

		return $meta;
	}

	/**
	 * Gestion des scripts JS
	 */
	public static function headScript () {
		$scripts = '';

		foreach (self::$scripts as $script) {
			$scripts .= '<script src="' . $script['url'] . '"';
			if (!empty($script['id'])) {
				$scripts .= ' id="' . $script['id'] . '"';
			}
			if ($script['defer']) {
				$scripts .= ' defer="defer"';
			}
			if ($script['async']) {
				$scripts .= ' async="async"';
			}
			$scripts .= '></script>';
			$scripts .= "\n";
		}

		return $scripts;
	}
	/**
	 * Prepend a `<script>` element.
	 * @param string $url
	 * @param bool $cond Conditional comment for IE, now deprecated and ignored
	 * @param bool $defer Use `defer` flag
	 * @param bool $async Use `async` flag
	 * @param string $id Add a script `id` attribute
	 */
	public static function prependScript($url, $cond = false, $defer = true, $async = true, $id = '') {
		array_unshift(self::$scripts, array (
			'url' => $url,
			'defer' => $defer,
			'async' => $async,
			'id' => $id,
		));
	}
/**
	 * Append a `<script>` element.
	 * @param string $url
	 * @param bool $cond Conditional comment for IE, now deprecated and ignored
	 * @param bool $defer Use `defer` flag
	 * @param bool $async Use `async` flag
	 * @param string $id Add a script `id` attribute
	 */
	public static function appendScript($url, $cond = false, $defer = true, $async = true, $id = '') {
		self::$scripts[] = array (
			'url' => $url,
			'defer' => $defer,
			'async' => $async,
			'id' => $id,
		);
	}

	/**
	 * Gestion des paramètres ajoutés à la vue
	 */
	public static function _param ($key, $value) {
		self::$params[$key] = $value;
	}
	public function attributeParams () {
		foreach (Minz_View::$params as $key => $value) {
			$this->$key = $value;
		}
	}
}
