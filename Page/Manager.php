<?php
/*
 * This file is part of the Sonata project.
 *
 * (c) Thomas Rabaix <thomas.rabaix@sonata-project.org>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */


namespace Sonata\PageBundle\Page;

use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Templating\EngineInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Request;

use Sonata\PageBundle\Model\BlockInterface;
use Sonata\PageBundle\Model\PageInterface;
use Sonata\PageBundle\Model\BlockManagerInterface;
use Sonata\PageBundle\Model\PageManagerInterface;
use Sonata\PageBundle\Block\BlockServiceInterface;
use Sonata\PageBundle\Cache\CacheInterface;
use Sonata\PageBundle\Cache\Invalidation\InvalidationInterface;
use Sonata\PageBundle\Cache\CacheElement;
use Sonata\AdminBundle\Admin\AdminInterface;


/**
 * The Manager class is in charge of retrieving the correct page (cms page or action page)
 *
 * An action page is linked to a symfony action and a cms page is a standalone page.
 *
 *
 * @author     Thomas Rabaix <thomas.rabaix@sonata-project.org>
 */
class Manager
{
    protected $routePages = array();

    protected $currentPage;

    protected $pageLoader;

    protected $blocks = array();

    protected $options = array();

    protected $blockServices = array();

    protected $logger;

    protected $blockManager;

    protected $pageManager;

    protected $templating;

    protected $debug = false;

    protected $pageAdmin;

    protected $blockAdmin;

    protected $cacheServices = array();

    protected $cacheInvalidation;

    public function __construct(
      PageManagerInterface $pageManager,
      BlockManagerInterface $blockManager,
      EngineInterface $templating,
      InvalidationInterface $cacheInvalidation
  )
    {
        $this->pageManager  = $pageManager;
        $this->blockManager = $blockManager;
        $this->templating   = $templating;
        $this->cacheInvalidation = $cacheInvalidation;
    }

    public function addCacheService($name, CacheInterface $cacheManager)
    {
        $this->cacheServices[$name] = $cacheManager;
    }

    /**
     * filter the `core.response` event to decorated the action
     *
     * @param Event $event
     * @param Response $response
     * @return
     */
    public function onCoreResponse($event)
    {
        $response    = $event->getResponse();
        $requestType = $event->getRequestType();
        $request     = $event->getRequest();

        if ($this->isDecorable($request, $requestType, $response)) {
            $page = $this->defineCurrentPage($request);

            // only decorate hybrid page and page with decorate = true
            if ($page && $page->isHybrid() && $page->getDecorate()) {
                $parameters = array(
                    'content'     => $response->getContent(),
                );

                $response = $this->renderPage($page, $parameters, $response);
            }
        }

        return $response;
    }

    public function addBlockService($name, BlockServiceInterface $service)
    {
        $this->blockServices[$name] = $service;

        $service->setManager($this);
    }

    public function renderPage(PageInterface $page, array $params = array(), Response $response = null)
    {
        $template = 'SonataPageBundle::layout.html.twig';
        if ($this->getCurrentPage()) {
            $template = $this->getCurrentPage()->getTemplate()->getPath();
        }

        $params['page']         = $page;
        $params['manager']      = $this;
        $params['page_admin']   = $this->getPageAdmin();
        $params['block_admin']  = $this->getBlockAdmin();

        $response = $this->templating->renderResponse($template, $params, $response);
        $response->setTtl($page->getTtl());

        return $response;
    }

    /**
     * return true is the page can be decorate with an outter template
     *
     * @param \Symfony\Component\HttpFoundation\Request $request
     * @param string $requestType
     * @param \Symfony\Component\HttpFoundation\Response $response
     * @return bool
     */
    public function isDecorable(Request $request, $requestType, Response $response)
    {
        if ($requestType != HttpKernelInterface::MASTER_REQUEST) {

            return false;
        }

        if (($response->headers->get('Content-Type') ?: 'text/html') != 'text/html') {

            return false;
        }

        if ($response->getStatusCode() != 200) {

            return false;
        }

        if ($request->headers->get('x-requested-with') == 'XMLHttpRequest') {
            return false;
        }

        $routeName = $request->get('_route');

        return $this->isRouteNameDecorable($routeName) && $this->isRouteUriDecorable($request->getRequestUri());
    }

    /**
     * @param string $routeName
     * @return bool
     */
    public function isRouteNameDecorable($routeName)
    {
        if (!$routeName) {
            return false;
        }

        foreach ($this->getOption('ignore_routes', array()) as $route) {
            if ($routeName == $route) {
                return false;
            }
        }

        foreach ($this->getOption('ignore_route_patterns', array()) as $routePattern) {
            if (preg_match($routePattern, $routeName)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param string $uri
     * @return bool
     */
    public function isRouteUriDecorable($uri)
    {
        foreach ($this->getOption('ignore_uri_patterns', array()) as $uriPattern) {
            if (preg_match($uriPattern, $uri)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Render a specialize block
     *
     * @param \Sonata\PageBundle\Model\BlockInterface $block
     * @param \Sonata\PageBundle\Model\PageInterface $page
     * @param boolean $useCache
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function renderBlock(BlockInterface $block, PageInterface $page, $useCache = true)
    {
        if ($this->getLogger()) {
            $this->getLogger()->info(sprintf('[cms::renderBlock] block.id=%d, block.type=%s ', $block->getId(), $block->getType()));
        }

        try {
            $service       = $this->getBlockService($block);
            $cacheManager  = $this->getCacheService($block);
            $cacheElement  = $service->getCacheElement($block);

            if ($useCache && $cacheManager->has($cacheElement)) {
                return $cacheManager->get($cacheElement);
            }

            $response = $service->execute($block, $page, $cacheManager->createResponse($cacheElement));

            if ($useCache) {
                $cacheElement->setValue($response);
                $cacheManager->set($cacheElement);
            }

            return $response;
        } catch (\Exception $e) {
            if ($this->getLogger()) {
                $this->getLogger()->crit(sprintf('[cms::renderBlock] block.id=%d - error while rendering block - %s', $block->getId(), $e->getMessage()));
            }

            if ($this->getDebug()) {
                throw $e;
            }

            return new Response;
        }
    }

    /**
     * @param string $name
     * @param \Sonata\PageBundle\Model\PageInterface $page
     * @param \Sonata\PageBundle\Model\BlockInterface $parentContainer
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function renderContainer($name, $page = null, BlockInterface $parentContainer = null)
    {
        $page = $this->getPage($page);

        if (!$page) {
            return $this->templating->render('SonataPageBundle:Block:block_no_page_available.html.twig');
        }

        $container = $this->findContainer($name, $page, $parentContainer);

        return $this->renderBlock($container, $page)->getContent();
    }

    /**
     * Return a PageInterface instance depends on the $page argument
     *
     * @param mixed $page
     * @return \Sonata\PageBundle\Model\PageInterface
     */
    public function getPage($page)
    {
        if (is_string($page)) { // page is a slug, load the related page
            $page = $this->getPageByRouteName($page);
        } else if ( is_numeric($page)) {
            $page = $this->getPageById($page);
        } else if (!$page) { // get the current page
            $page = $this->getCurrentPage();
        }

        if (!$page instanceof PageInterface) {
            throw new \RunTimeException('Unable to retrieve the page');
        }

        return $page;
    }

    /**
     * @param string $name
     * @param \Sonata\PageBundle\Model\PageInterface $page
     * @param null|\Sonata\PageBundle\Model\BlockInterface $parentContainer
     * @return bool|null|\Sonata\PageBundle\Model\BlockInterface
     */
    public function findContainer($name, PageInterface $page, BlockInterface $parentContainer = null)
    {
        $container = false;

        if ($parentContainer) {
            // parent container is set, nothing to find, don't need to loop across the
            // name to find the correct container (main template level)
            $container = $parentContainer;
        }

        // first level blocks are containers
        if (!$container && $page->getBlocks()) {
            foreach ($page->getBlocks() as $block) {
                if ($block->getSetting('name') == $name) {

                    $container = $block;
                    break;
                }
            }
        }

        if (!$container) {
            $container = $this->blockManager->createNewContainer(array(
                'enabled' => true,
                'page' => $page,
                'name' => $name,
                'position' => 1
            ));

            if ($parentContainer) {
                $container->setParent($parentContainer);
            }

            $this->blockManager->save($container);
        }

        return $container;
    }


    /**
     * Return the block service linked to the link
     *
     * @param \Sonata\PageBundle\Block\BlockInterface $block
     * @return \Sonata\PageBundle\Block\BlockServiceInterface
     */
    public function getBlockService(BlockInterface $block)
    {
        if (!$this->hasBlockService($block->getType())) {
            if ($this->getDebug()) {
                throw new \RuntimeException(sprintf('The block service `%s` referenced in the block `%s` does not exists', $block->getType(), $block->getId()));
            }

            if ($this->getLogger()){
                $this->getLogger()->crit(sprintf('[cms::getBlockService] block.id=%d - service:%s does not exists', $block->getId(), $block->getType()));
            }

            return false;
        }

        return $this->blockServices[$block->getType()];
    }

    /**
     * @throws \RuntimeException
     * @param \Sonata\PageBundle\Model\BlockInterface $block
     * @return array|bool
     */
    public function getCacheService(BlockInterface $block)
    {
        if (!$this->hasCacheService($block->getType())) {
            throw new \RuntimeException(sprintf('The block service `%s` referenced in the block `%s` does not exists', $block->getType(), $block->getId()));
        }

        return $this->cacheServices[$block->getType()];
    }

    /**
     * Returns related cache services
     *
     * @return array
     */
    public function getCacheServices()
    {
        return $this->cacheServices;
    }

    /**
     *
     * @param sring $id
     * @return boolean
     */
    public function hasBlockService($id)
    {
        return isset($this->blockServices[$id]) ? true : false;
    }

    /**
     *
     * @param sring $id
     * @return boolean
     */
    public function hasCacheService($id)
    {
        return isset($this->cacheServices[$id]) ? true : false;
    }

    public function invalidate(CacheElement $cacheElement)
    {
        $this->cacheInvalidation->invalidate($this->getCacheServices(), $cacheElement);
    }

    /**
     * return a fully loaded page ( + blocks ) from a route name
     *
     * if the page does not exists then the page is created.
     *
     * @param string $slug
     * @return Application\Sonata\PageBundle\Model\PageInterface
     */
    public function getPageBySlug($slug)
    {
        $page = $this->pageManager->getPageBySlug($slug);

        if ($page) {
            $this->loadBlocks($page);
        }

        return $page;
    }

    /**
     * return a fully loaded page ( + blocks ) from a route name
     *
     * if the page does not exists then the page is created.
     *
     * @param string $routeName
     * @return \Sonata\PageBundle\Model\PageInterface
     */
    public function getPageByRouteName($routeName, $create = true)
    {
        if (!isset($this->routePages[$routeName])) {
            $page = $this->pageManager->getPageByName($routeName);

            if (!$page && !$create) {
                throw new \RuntimeException(sprintf('Unable to find the page : %s', $routeName));
            } else if (!$page) {
                $page = $this->createPage($routeName);
            }

            $this->loadBlocks($page);
            $this->routePages[$routeName] = $page;
        }

        return $this->routePages[$routeName];
    }

    /**
     * return a fully loaded page ( + blocks ) from a route name
     *
     * if the page does not exists then the page is created.
     *
     * @param integer $routeName
     * @return \Sonata\PageBundle\Model\PageInterface
     */
    public function getPageById($id)
    {
        $page = $this->pageManager->findOneBy(array('id' => $id));

        $this->loadBlocks($page);

        return $page;
    }

    /**
     * @throws \RuntimeException
     * @param string $routeName
     * @return \Sonata\PageBundle\Model\PageInterface
     */
    public function createPage($routeName)
    {
        if (!$this->getDefaultTemplate()) {
            throw new \RuntimeException('No default template defined');
        }

        $page = $this->pageManager->createNewPage(array(
            'template' => $this->getDefaultTemplate(),
            'enabled'  => true,
            'routeName' => $routeName,
            'name'      => $routeName,
            'loginRequired' => false,
        ));

        $this->pageManager->save($page);

        return $page;
    }
    /**
     * return the default template used in the current application
     *
     * @return bool | Application\Sonata\PageBundle\Entity\Template
     */
    public function getDefaultTemplate()
    {
        return $this->pageManager->getDefaultTemplate();
    }

    /**
     * return the current page
     *
     * if the current route linked to a CMS page ( route name = `page_slug`)
     *   then the page is retrieve by using a slug
     *   otherwise the page is loaded from the route name
     *
     * @param \Symfony\Component\HttpFoundation\Request $request
     * @return \Sonata\PageBundle\Model\PageInterface
     */
    public function defineCurrentPage($request)
    {
        $routeName = $request->get('_route');

        if ($this->currentPage) {
            return $this->currentPage;
        }

        if ($routeName == 'page_slug') { // true cms page
            return null;
        } else { // hybrid page, ie an action is used
            $this->currentPage = $this->getPageByRouteName($routeName);

            if (!$this->currentPage && $this->getLogger()) {
                $this->getLogger()->crit(sprintf('[page:getCurrentPage] no page available for route : %s', $routeName));
            }
        }

        return $this->currentPage;
    }

    /**
     * Returns the current page
     *
     * @return \Sonata\PageBundle\Model\PageInterface
     */
    public function getCurrentPage()
    {
        return $this->currentPage;
    }

    public function setCurrentPage(PageInterface $page)
    {
        $this->currentPage = $page;
    }

    /**
     *
     * @param string $id
     * @return \Sonata\PageBundle\Model\BlockInterface
     */
    public function getBlock($id)
    {
        if (!isset($this->blocks[$id])) {
            $this->blocks[$id] = $this->blockManager->getBlock($id);
        }

        return $this->blocks[$id];
    }

    /**
     * load all the related nested blocks linked to one page.
     *
     * @param \Sonata\PageBundle\Model\PageInterface $page
     * @return void
     */
    public function loadBlocks(PageInterface $page)
    {
        $blocks = $this->blockManager->loadPageBlocks($page);

        // save a local cache
        foreach ($blocks as $block) {
            $this->blocks[$block->getId()] = $block;
        }
    }

    /**
     * save the block order from the page disposition
     *
     * Format :
     *      Array
     *      (
     *          [cms-block-2] => Array
     *              (
     *                  [type] => core.container
     *                  [child] => Array
     *                      (
     *                          [cms-block-4] => Array
     *                              (
     *                                  [type] => core.action
     *                                  [child] =>
     *                              )
     *
     *                      )
     *
     *              )
     *
     *          [cms-block-5] => Array
     *              (
     *                  [type] => core.container
     *                  [child] =>
     *              )
     *
     *          [cms-block-8] => Array
     *              (
     *                  [type] => core.container
     *                  [child] => Array
     *                      (
     *                          [cms-block-9] => Array
     *                              (
     *                                  [type] => core.container
     *                                  [child] => Array
     *                                      (
     *                                          [cms-block-3] => Array
     *                                              (
     *                                                  [type] => core.text
     *                                                  [child] =>
     *                                              )
     *
     *                                      )
     *
     *                              )
     *
     *                      )
     *
     *              )
     *
     *      )
     *
     * @param  $data
     * @return void
     */
    public function savePosition($data)
    {
        return $this->blockManager->saveBlocksPosition($data);
    }

    public function setBlocks($blocks)
    {
        $this->blocks = $blocks;
    }

    public function getBlocks()
    {
        return $this->blocks;
    }

    public function setOptions(array $options = array())
    {
        $this->options = $options;
    }

    public function getOptions()
    {
        return $this->options;
    }

    public function setOption($name, $value)
    {
        $this->options[$name] = $value;
    }

    public function getOption($name, $default = null)
    {
        return isset($this->options[$name]) ? $this->options[$name] : $default;
    }

    public function setRoutePages($route_pages)
    {
        $this->routePages = $route_pages;
    }

    public function getRoutePages()
    {
        return $this->routePages;
    }

    public function setLogger($logger)
    {
        $this->logger = $logger;
    }

    public function getLogger()
    {
        return $this->logger;
    }

    public function setDebug($debug)
    {
        $this->debug = $debug;
    }

    /**
     *
     * @return bool
     */
    public function getDebug()
    {
        return $this->debug;
    }

    /**
     * @return \Sonata\PageBundle\Model\BlockManagerInterface
     */
    public function getBlockManager()
    {
        return $this->blockManager;
    }

    /**
     * @return \Sonata\PageBundle\Model\PageManagerInterface
     */
    public function getPageManager()
    {
        return $this->pageManager;
    }

    /**
     * @param array $blockServices
     * @return void
     */
    public function setBlockServices(array $blockServices)
    {
        $this->blockServices = $blockServices;
    }

    /**
     * @return array
     */
    public function getBlockServices()
    {
        return $this->blockServices;
    }

  public function setBlockAdmin(AdminInterface $blockAdmin)
  {
    $this->blockAdmin = $blockAdmin;
  }

  public function getBlockAdmin()
  {
    return $this->blockAdmin;
  }

  public function setPageAdmin(AdminInterface $pageAdmin)
  {
    $this->pageAdmin = $pageAdmin;
  }

  public function getPageAdmin()
  {
    return $this->pageAdmin;
  }
}