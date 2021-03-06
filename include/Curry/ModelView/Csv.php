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
 *
 * @package Curry\ModelView
 */
class Curry_ModelView_Csv extends Curry_ModelView_Abstract {
	protected $view = null;
	protected $filename = null;
	protected $includeHeaders = true;

	public function getModelClass()
	{
		return null;
	}

	public function setView($view)
	{
		$this->view = $view;
	}

	public function getView()
	{
		return $this->view;
	}

	public function setIncludeHeaders($includeHeaders)
	{
		$this->includeHeaders = $includeHeaders;
	}

	public function getIncludeHeaders()
	{
		return $this->includeHeaders;
	}

	public function render(Curry_Backend $backend, array $params)
	{
		$view = $this->view ? $this->view : $this->parentView;
		if (!($view instanceof Curry_ModelView_List))
			throw new Exception('Expected view to be of type Curry_ModelView_List, got '.get_class($view));
		$view = clone $view;

		// Send response headers to the browser
		$filename = $this->filename ? $this->filename : $view->getOption('title') . ".csv";
		header('Content-Type: text/csv');
		header('Content-Disposition: attachment; filename='.Curry_String::escapeQuotedString($filename));
		$fp = fopen('php://output', 'w');

		// Print column headers
		$headers = array();
		foreach($view->getOption('columns') as $name => $opts) {
			// TODO: should we really ignore display/escape option?
			$view->addColumn($name, array('display'=>null, 'escape' => false));
			$headers[] = $opts['label'];
		}
		if ($this->includeHeaders) {
			Curry_Util::fputcsv($fp, $headers);
		}

		// Print rows
		$page = 0;
		$maxPerPage = 100;
		$view->setOptions(array('maxPerPage' => $maxPerPage));
		do {
			$results = $view->getJson(array('p'=>++$page));
			foreach($results['rows'] as $row)
				Curry_Util::fputcsv($fp, $row);
		} while(count($results['rows']) == $maxPerPage);
		fclose($fp);
		exit;
	}
}