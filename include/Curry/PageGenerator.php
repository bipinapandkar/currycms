<?php
/**
 * Curry CMS
 *
 * LICENSE
 *
 * This source file is subject to the GPL license that is bundled
 * with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://currycms.com/license
 *
 * @category   Curry CMS
 * @package    Curry
 * @copyright  2011-2012 Bombayworks AB (http://bombayworks.se)
 * @license    http://currycms.com/license GPL
 * @link       http://currycms.com
 */

/**
 * Base class for generating pages.
 * 
 * This takes a PageRevision and inserts its modules into a template
 * and return the generated content.
 * 
 * @package Curry
 *
 */
class Curry_PageGenerator
{
	/**
	 * The pageRevision to generate.
	 *
	 * @var PageRevision
	 */
	protected $pageRevision;
	
	/**
	 * The request object.
	 *
	 * @var Curry_Request
	 */
	protected $request;
	
	/**
	 * Keeps track of the cached content when caching a module.
	 *
	 * @var array
	 */
	protected $moduleCache;
	
	/**
	 * Holds module debug info.
	 *
	 * @var array
	 */
	protected $moduleDebugInfo;
	
	/**
	 * Constructor
	 *
	 * @param PageRevision $pageRevision
	 * @param Curry_Request $request
	 */
	public function __construct(PageRevision $pageRevision, Curry_Request $request)
	{
		$this->pageRevision = $pageRevision;
		$this->request = $request;
	}
	
	/**
	 * Return the current Curry_Request object that belongs to the page being generated.
	 *
	 * @return Curry_Request
	 */
	public function getRequest()
	{
		return $this->request;
	}
	
	/**
	 * Return the Page that is being generated.
	 *
	 * @return Page
	 */
	public function getPage()
	{
		return $this->pageRevision->getPage();
	}
	
	/**
	 * Get the mime-type for this page.
	 *
	 * @return string
	 */
	public function getContentType()
	{
		return "text/plain";
	}
	
	/**
	 * Insert module and return generated content.
	 *
	 * @param Curry_PageModuleWrapper $pageModuleWrapper
	 * @return string
	 */
	protected function insertModule(Curry_PageModuleWrapper $pageModuleWrapper)
	{
		Curry_Core::log(($pageModuleWrapper->getEnabled() ? 'Inserting' : 'Skipping').' module "'.$pageModuleWrapper->getName().'" of type "'.$pageModuleWrapper->getClassName() . '" with target "'.$pageModuleWrapper->getTarget().'"');
		
		if(!$pageModuleWrapper->getEnabled())
			return "";

		$cached = false;
		$devMode = Curry_Core::$config->curry->developmentMode;
		if ($devMode) {
			$time = microtime(true);
			$sqlQueries = Curry_Propel::getQueryCount();
			$userTime = Curry_Util::getCpuTime('u');
			$systemTime = Curry_Util::getCpuTime('s');
			$memoryUsage = memory_get_usage(true);
		}

		$this->moduleCache = array();
		$module = $pageModuleWrapper->createObject();
		$module->setPageGenerator($this);

		$cp = $module->getCacheProperties();
		$cacheName = $this->getModuleCacheName($pageModuleWrapper, $module);

		// try to use cached content
		if($cp !== null && ($cache = Curry_Core::$cache->load($cacheName)) !== false) {
			$cached = true;
			$this->insertCachedModule($cache);
			$content = $cache['content'];
		} else {
			$template = null;
			if ($pageModuleWrapper->getTemplate())
				$template = Curry_Twig_Template::loadTemplate($pageModuleWrapper->getTemplate());
			else if ($module->getDefaultTemplate())
				$template = Curry_Twig_Template::loadTemplateString($module->getDefaultTemplate());
			if($template && $template->getEnvironment()) {
				$twig = $template->getEnvironment();
				$twig->addGlobal('module', array(
					'Id' => $pageModuleWrapper->getPageModuleId(),
					'ClassName' => $pageModuleWrapper->getClassName(),
					'Name' => $pageModuleWrapper->getName(),
					'ModuleDataId' => $pageModuleWrapper->getModuleDataId(),
					'Target' => $pageModuleWrapper->getTarget(),
				));
			}
			$content = (string)$module->showFront($template);
			
			if($cp !== null) {
				$this->moduleCache['content'] = $content;
				$this->saveModuleCache($cacheName, $cp->getLifetime());
			}
		}

		if ($devMode) {
			$time = microtime(true) - $time;
			$userTime = Curry_Util::getCpuTime('u') - $userTime;
			$systemTime = Curry_Util::getCpuTime('s') - $systemTime;
			$memoryUsage = memory_get_usage(true) - $memoryUsage;
			$sqlQueries = $sqlQueries !== null ? Curry_Propel::getQueryCount() - $sqlQueries : null;

			$cpuLimit = Curry_Core::$config->curry->debug->moduleCpuLimit;
			$timeLimit = Curry_Core::$config->curry->debug->moduleTimeLimit;
			$memoryLimit = Curry_Core::$config->curry->debug->moduleMemoryLimit;
			$sqlLimit = Curry_Core::$config->curry->debug->moduleSqlLimit;

			if (($userTime + $systemTime) > $cpuLimit || $time > $timeLimit)
				trace_warning('Module generation time exceeded limit');
			if ($memoryUsage > $memoryLimit)
				trace_warning('Module memory usage exceeded limit');
			if ($sqlQueries > $sqlLimit)
				trace_warning('Module sql query count exceeded limit');

			// add module debug info
			$this->moduleDebugInfo[] = array(
				$pageModuleWrapper->getName(),
				$pageModuleWrapper->getClassName(),
				$pageModuleWrapper->getTemplate(),
				$pageModuleWrapper->getTarget(),
				$cached,
				round($time * 1000.0),
				round(($userTime + $systemTime) * 1000.0),
				Curry_Util::humanReadableBytes($memoryUsage),
				Curry_Util::humanReadableBytes(memory_get_peak_usage(true)),
				$sqlQueries !== null ? $sqlQueries : 'n/a',
			);
		}

		return $content;
	}
	
	/**
	 * Save module content to cache.
	 *
	 * @param string $cacheName
	 * @param int|bool|null $lifetime
	 */
	protected function saveModuleCache($cacheName, $lifetime)
	{
		Curry_Core::$cache->save($this->moduleCache, $cacheName, array(), $lifetime);
	}
	
	/**
	 * Inserting cached content.
	 *
	 * @param array $cache
	 */
	protected function insertCachedModule($cache)
	{
	}
	
	/**
	 * Get unique name for storing module cache.
	 *
	 * @param Curry_PageModuleWrapper $pageModuleWrapper
	 * @param Curry_Module $module
	 * @return string
	 */
	private function getModuleCacheName(Curry_PageModuleWrapper $pageModuleWrapper, Curry_Module $module)
	{
		$params = array(
			'_moduleDataId' => $pageModuleWrapper->getModuleDataId(),
			'_template' => $pageModuleWrapper->getTemplate()
		);
		
		$cp = $module->getCacheProperties();
		if($cp !== null)
			$params = array_merge($params, $cp->getParams());
			
		return __CLASS__.'_Module_'.md5(serialize($params));
	}
	
	/**
	 * Function to execute before generating page.
	 */
	protected function preGeneration()
	{
		$this->moduleDebugInfo = array();
	}
	
	/**
	 * Function to execute after generating page.
	 */
	protected function postGeneration()
	{
		if (Curry_Core::$config->curry->developmentMode) {
			$totalTime = 0;
			foreach($this->moduleDebugInfo as $mdi)
				$totalTime += $mdi[5];
			$labels = array('Name', 'Class', 'Template', 'Target', 'Cached','Time (ms)', 'Cpu (ms)', 'Memory Delta', 'Memory Peak', 'Queries');
			Curry_Core::log(array(
					"Modules(".count($this->moduleDebugInfo)."): ".round($totalTime / 1000.0, 3)."s",
					array_merge(array($labels), $this->moduleDebugInfo)), Curry_Core::LOG_TABLE);
		}
	}
	
	/**
	 * Generate a page, and return module content as an associative array.
	 *
	 * @param array $options
	 * @return string
	 */
	public function generate(array $options = array())
	{
		$this->preGeneration();

		// Load page modules
		$moduleContent = array();
		$pageModuleWrappers = $this->getPageModuleWrappers();
		foreach($pageModuleWrappers as $pageModuleWrapper) {
			if(isset($options['pageModuleId']) && $pageModuleWrapper->getPageModuleId() != $options['pageModuleId'])
				continue;
			if(isset($options['indexing']) && $options['indexing'] && !$pageModuleWrapper->getPageModule()->getSearchVisibility())
				continue;
			
			$target = $pageModuleWrapper->getTarget();
			$content = $this->insertModule($pageModuleWrapper);
			if(isset($moduleContent[$target])) {
				$moduleContent[$target] .= $content;
			} else {
				$moduleContent[$target] = (string)$content;
			}
		}
		
		$this->postGeneration();
		return $moduleContent;
	}

	protected function getGlobals()
	{
		$lang = Curry_Language::getLanguage();
		return array(
			'ContentType' => $this->getContentType(),
			'Encoding' => $this->getOutputEncoding(),
			'language' => $lang ? $lang->toArray() : null,
			'page' => $this->pageRevision->getPage()->toTwig(),
			'request' => $this->request,
		);
	}
	
	/**
	 * Render a page and return content.
	 *
	 * @param array $vars
	 * @param array $options
	 * @return string
	 */
	public function render(array $vars = array(), array $options = array())
	{
		$twig = Curry_Twig_Template::getSharedEnvironment();
		// Todo: Rename curry to app?
		$appVars = Curry_Application::getInstance()->getGlobalVariables();
		if (isset($vars['curry']))
			Curry_Array::extend($appVars, $vars['curry']);
		$vars['curry'] = Curry_Array::extend($appVars, $this->getGlobals());
		foreach($vars as $k => $v)
			$twig->addGlobal($k, $v);
		
		$moduleContent = $this->generate($options);
		if(isset($options['pageModuleId'])) {
			$pageModuleId = $options['pageModuleId'];
			$pageModuleWrappers = $this->getPageModuleWrappers();
			if(isset($pageModuleWrappers[$pageModuleId])) {
				$pageModuleWrapper = $pageModuleWrappers[$pageModuleId];
				return $moduleContent[$pageModuleWrapper->getTarget()];
			} else {
				throw new Exception('PageModule with id = '.$pageModuleId.' not found on page.');
			}
		}
		$template = $this->getTemplateObject();
		return $this->renderTemplate($template, $moduleContent);
	}

	public function renderTemplate($template, $moduleContent)
	{
		return $template->render($moduleContent);
	}
	
	/**
	 * Render page and return content to browser (stdout).
	 *
	 * @param array $vars
	 * @param array $options
	 */
	public function display(array $vars = array(), array $options = array())
	{
		$this->sendContentType();
		$this->sendContent($this->render($vars, $options));
	}
	
	/**
	 * Return content to browser.
	 *
	 * @param string $content
	 */
	protected function sendContent($content)
	{
		$internalEncoding = Curry_Core::$config->curry->internalEncoding;
		$outputEncoding = $this->getOutputEncoding();
		if ($outputEncoding && $internalEncoding != $outputEncoding) {
			trace_warning('Converting output from internal coding');
			$content = iconv($internalEncoding, $outputEncoding."//TRANSLIT", $content);
		}
		echo $content;
	}
	
	/**
	 * Set content-type header.
	 */
	protected function sendContentType()
	{
		header("Content-Type: ".$this->getContentTypeWithCharset());
	}
	
	/**
	 * Get the output encoding for this page. If the encoding hasnt been set for this page, the encoding set in the configuration will be used.
	 *
	 * @return string
	 */
	public function getOutputEncoding()
	{
		return $this->getPage()->getInheritedProperty('Encoding', Curry_Core::$config->curry->outputEncoding);
	}
	
	/**
	 * Get value of HTTP Content-type header.
	 *
	 * @return string
	 */
	public function getContentTypeWithCharset()
	{
		$contentType = $this->getContentType();
		$outputEncoding = $this->getOutputEncoding();
		if($outputEncoding)
			$contentType .= "; charset=" . $outputEncoding;
		return $contentType;
	}
	
	/**
	 * Get the root template (aka page template) for the PageRevision we are rendering.
	 *
	 * @return string
	 */
	protected function getRootTemplate()
	{
		return $this->pageRevision->getInheritedProperty('Template');
	}
	
	/**
	 * Get an array of Curry_PageModuleWrapper objects for all modules on the PageRevision we are rendering.
	 *
	 * @return array
	 */
	protected function getPageModuleWrappers()
	{
		$langcode = (string)Curry_Language::getLangCode();
		$cacheName = __CLASS__ . '_ModuleWrappers_' . $this->pageRevision->getPageRevisionId() . '_' . $langcode;
		
		if(($moduleWrappers = Curry_Core::$cache->load($cacheName)) === false) {
			$moduleWrappers = $this->pageRevision->getPageModuleWrappers($langcode);
			Curry_Core::$cache->save($moduleWrappers, $cacheName);
		}
			
		return $moduleWrappers;
	}
	
	/**
	 * Get the template object for this PageRevision.
	 *
	 * @return Curry_Twig_Template
	 */
	public function getTemplateObject()
	{
		$rootTemplate = $this->getRootTemplate();
		if(!$rootTemplate)
			throw new Exception("Page has no root template");
		return Curry_Twig_Template::loadTemplate($rootTemplate);
	}
}
