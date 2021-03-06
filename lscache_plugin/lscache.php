<?php

/**
 *  @since      1.0.0
 *  @author     LiteSpeed Technologies <info@litespeedtech.com>
 *  @copyright  Copyright (c) 2017-2018 LiteSpeed Technologies, Inc. (https://www.litespeedtech.com)
 *  @license    https://opensource.org/licenses/GPL-3.0
 */

defined('_JEXEC') or die;

use Joomla\Registry\Registry;

/**
 * LiteSpeed Cache Plugin for Joomla running on LiteSpeed Webserver (LSWS).
 *
 * @since  1.0
 */
class plgSystemLSCache extends JPlugin
{
    const MODULE_ESI=1;
    const MODULE_PURGEALL=2;
    const MODULE_PURGETAG=3;
    
    const CATEGORY_CONTEXTS = array('com_categories.category', 'com_banners.category','com_contact.category','com_content.category','com_newsfeeds.category','com_users.category',
            'com_categories.categories', 'com_banners.categories','com_contact.categories','com_content.categories','com_newsfeeds.categories','com_users.categories');
    const CONTENT_CONTEXTS = array('com_content.article', 'com_content.featured','com_banner.banner', 'com_contact.contact', 'com_newsfeeds.newsfeed');

	protected $app;
    protected $settings;
    protected $cacheEnabled;
    protected $esiEnabled;
    protected $pageCachable = false;
    protected $esion = false;
    protected $menuItem;
    protected $lscInstance;
    protected $cacheTags = array();
    protected $pageElements = array();

    /**
     * Read LSCache Settings.
     *
     * @since   0.1
     */
    public function __construct(&$subject, $config)
    {
        parent::__construct($subject, $config);

        $this->settings = JComponentHelper::getParams('com_lscache');
        $this->cacheEnabled = $this->settings->get('cacheEnabled', 1) == 1 ? true : false;
        $this->esiEnabled = $this->settings->get('esiEnabled', 1) == 1 ? true : false;
        if(!$this->cacheEnabled){
            return;
        }
        
        // Server type
        if ( ! defined( 'LITESPEED_SERVER_TYPE' ) ) {
            if ( isset( $_SERVER['HTTP_X_LSCACHE'] ) && $_SERVER['HTTP_X_LSCACHE'] ) {
                define( 'LITESPEED_SERVER_TYPE', 'LITESPEED_SERVER_ADC' ) ;
            }
            elseif ( isset( $_SERVER['LSWS_EDITION'] ) && strpos( $_SERVER['LSWS_EDITION'], 'Openlitespeed' ) === 0 ) {
                define( 'LITESPEED_SERVER_TYPE', 'LITESPEED_SERVER_OLS' ) ;
            }
            elseif ( isset( $_SERVER['SERVER_SOFTWARE'] ) && $_SERVER['SERVER_SOFTWARE'] == 'LiteSpeed' ) {
                define( 'LITESPEED_SERVER_TYPE', 'LITESPEED_SERVER_ENT' ) ;
            }
            else {
                define( 'LITESPEED_SERVER_TYPE', 'NONE' ) ;
            }
        }

        // Checks if caching is allowed via server variable
        if ( ! empty ( $_SERVER['X-LSCACHE'] ) ||  LITESPEED_SERVER_TYPE === 'LITESPEED_SERVER_ADC' || defined( 'LITESPEED_CLI' ) ) {
            ! defined( 'LITESPEED_ALLOWED' ) &&  define( 'LITESPEED_ALLOWED', true ) ;
        }
        else{
            $this->cacheEnabled = false;
            return;
        }

        // ESI const defination
        if ( ! defined( 'LITESPEED_ESI_SUPPORT' ) ) {
            define( 'LITESPEED_ESI_SUPPORT', LITESPEED_SERVER_TYPE !== 'LITESPEED_SERVER_OLS' ? true : false ) ;
        }

        JLoader::register('LiteSpeedCacheBase', dirname(__FILE__) . '/lscachebase.php', true);
        JLoader::register('LiteSpeedCacheCore', dirname(__FILE__) . '/lscachecore.php', true);
        $this->lscInstance = new LiteSpeedCacheCore();
		if (!$this->app)
		{
			$this->app = JFactory::getApplication();
		}
        
    }

    /**
     * No cache for backend pages, for page with error messages, for PostBack request, for logged in user, for expired sessions, for exclude pages, etc.
     *
     * @since   1.0.0
     */
    public function onAfterRoute()
    {
        if(!$this->cacheEnabled){
            return;
        }
        
        $app  = $this->app;
        $this->pageCachable = false;

        if ($app->isAdmin())
		{
			return;
		}
        
        if(JDEBUG){
            return;
        }
        
		if (count($app->getMessageQueue()))
		{
			return;
		}
        
        if($app->input->getMethod() != 'GET'){
            return;
        }

        $this->menuItem = $app->getMenu()->getActive();
        
		$user = JFactory::getUser();
        
        if(!$user->get('guest')){
            $this->lscInstance->checkPrivateCookie();
            $varyKey = self::getVaryKey("Login");
            if(!$this->checkVary($varyKey)){
                return;
            }
            $loginCachable =  $this->settings->get('loginCachable', 0) == 1 ? true : false;
            if(!$loginCachable){
                return;
            }
            if($this->isLoginExcluded()){
                return;
            }
        }
        else{
            $varyKey = self::getVaryKey("Logout");
            if(!$this->checkVary($varyKey)){
                return;
            }
        }

        if($this->menuItem){
            $this->cacheTags[] = "com_menus:" . $this->menuItem->id;
            $link = $this->menuItem->link;
            if($this->menuItem->type=='alias'){
                $menuParams = new Registry;
                $menuParams->loadString($this->menuItem->params);
                $menuid = $menuParams->get('aliasoption');
                $menuItem = $app->getMenu()->getitem($menuid);
                $this->cacheTags[] = "com_menus:" . $menuItem->id;
                $link = $menuitem->link;
            }
            $link =  str_replace('index.php?', '', $link );
        }
        else{
            $link = JUri::getInstance()->getQuery();
        }
        
        $this->pageElements = $this->explode2($link, '&', '=');

        if($this->isExcluded()){
            return;
        }
        
        $this->pageCachable = true;
        
    }

    
    public function onAfterRenderModule($module, $attribs)
    {
        if(!$this->pageCachable){
            return;
        }
        
        $cacheType = $this->getModuleCacheType($module);
        
        if(LITESPEED_ESI_SUPPORT && $this->esiEnabled && ($cacheType==self::MODULE_ESI) ){

            $tag = $this->lscInstance->getSiteOnlyTag() . 'com_modules:' . $module->id;
            $device = "desktop";
            if($this->app->client->mobile){
                $device = 'mobile';
            }
            
            if($module->lscache_type==1){
                $module->content = '<esi:include src="index.php?option=com_lscache&view=esi&moduleid=' . $module->id . '&device=' . $device . '&language=' . JFactory::getLanguage()->getTag() . $this->getModuleAttribs($attribs) . '" cache-control="public,no-vary" cache-tag="'. $tag . '" />';
            }
            else if($module->lscache_type==-1){
                $tag = 'public:' . $tag . ',' . $tag;
                $module->content = '<esi:include src="index.php?option=com_lscache&view=esi&moduleid=' . $module->id . '&device=' . $device . '&language=' . JFactory::getLanguage()->getTag() . $this->getModuleAttribs($attribs) . '" cache-control="private,no-vary" cache-tag="'. $tag .'" />';
            }
            else if($module->lscache_type==0){
                $module->content = '<esi:include src="' . 'index.php?option=com_lscache&view=esi&moduleid=' . $module->id  . $this->getModuleAttribs($attribs) . '" cache-control="no-cache"/>';
            }
            
            $this->esion = true;
            return;
        }
        
        if($module->module == "mod_menu"){
            if($cacheType==self::MODULE_PURGEALL){
                $this->cacheTags[] = 'com_menus';
                return;
            }
            $moduleParams = new Registry;
            $moduleParams->loadString($module->params);
            $menuid = $moduleParams->get('base', FALSE);
            $menutype = $moduleParams->get('menutype', FALSE);
            if($menuid){
                $this->cacheTags[] = 'com_menus:' . $menuid;
            }
            else if($menutype){
                $this->cacheTags[] = 'com_menus:' . $menutype;
            }
        }
        else if($module->module == "mod_k2_content"){
            $this->cacheTags[] = 'com_k2';
        }
        
    }

    
    public function onContentPrepare($context, &$row, &$params, $page = 0)
    {
        if(!$this->pageCachable){
            return;
        }
        
        if(strpos($context, "mod_")==0){
            return;
        }
        
        if(isset($this->pageElements["id"])){
            if(empty($row->id)){
                return;
            }
            else if( $row->id != $this->pageElements["id"]){
                return;
            }
        }
        else if(isset($this->pageElements["context"])){
            return;
        }
        
        $this->pageElements["context"] = $context;
        $this->pageElements["content"] = $row;
    }

    
    public function onAfterRender(){

        if(!$this->pageCachable){
            return;
        }
        
        if (function_exists('http_response_code')) {
            $httpcode = http_response_code();
            if($httpcode>200){
                $this->log("Http Response Code Not Cachable:" . $httpcode);
                return;
            }
        }
        
        if(isset($this->pageElements["context"])){
            $context = $this->pageElements["context"];
            if($context && in_array($context, self::CATEGORY_CONTEXTS)){
                $context = 'com_categories.category';
            }
        }

        $option = $this->pageElements["option"];
        if(isset($context)){
            $option = $this->getOption($context);
        }

        if(isset($this->pageElements["id"])){
            $id = $this->pageElements["id"];
        }
        
        if(isset($this->pageElements["content"])){
            $content = $this->pageElements["content"];
            if($content && isset($content->id)){
                $id= $content->id;
            }
        }
        
        if(!$option){
            return;
        }
        else if(isset($id) && in_array($option, array('com_content', 'com_contact', 'com_banners', 'com_newsfeed', 'com_k2', 'com_categories', 'com_users'))){
            $this->cacheTags[] =  $option . ':' . $id;
        }
        else if(isset($content) && $content instanceof JTable){
            $tableName = str_replace('#__', "DB", $content->getTableName());
            $tag = $tableName . ':' . implode('-', $content->getPrimaryKey());
            $this->cacheTags[] = $tag;
        }
        else{
            $this->cacheTags[] =  $option;
        }

        $cacheTags = implode(',', $this->cacheTags);
        $cacheTimeout = $this->settings->get('cacheTimeout', 1500) * 60;
        if($this->menuItem->home){
            $cacheTimeout = $this->settings->get('homePageCacheTimeout', 1500) * 60;
        }
        $this->lscInstance->config(array("public_cache_timeout"=>$cacheTimeout, "private_cache_timeout"=>$cacheTimeout));        
        $this->lscInstance->cachePublic($cacheTags, $this->esion);
        $this->log();
    }
    
    
    private function getOption($context){
        $parts = explode(".", $context);
        return $parts[0];
    }

    
    public function onContentAfterSave($context, $row, $isNew)
    {
        if(!$this->cacheEnabled){
            return;
        }

        if($isNew){
            if (in_array($context, self::CONTENT_CONTEXTS) && $row->catid){
                $this->lscInstance->purgePublic('com_categories, com_categories:'.$row->catid);
                $this->log();
                return;
            }
            $this->lscInstance->purgePublic($this->getOption($context));
            $this->log();
            return;
        }
        
        $this->purgeContent($context, $row);
        $this->log();
    }

    
    public function onContentAfterDelete($context, $row)
    {
        if(!$this->cacheEnabled){
            return;
        }

        $this->purgeContent($context, $row);
        $this->log();
    }    
    
    
    public function onUserAfterSave($user, $isNew, $success, $msg){
        if(!$this->cacheEnabled){
            return;
        }

        if(!$success){
            return;
        }
        
        if($isNew){
            return;
        }
        
        $this->purgeContent("com_users.user", $user);
        $this->log();
    }

	public function onUserAfterLogin($options){

        if ($this->app->isAdmin())
		{
			return;
		}
        $this->lscInstance->checkPrivateCookie();
        $varyKey = self::getVaryKey("Login");
        $this->checkVary($varyKey);
        if($this->esiEnabled){
            $this->lscInstance->purgeAllPrivate();
        }
        
    }
    

	public function onUserAfterLogout($options){

        if ($this->app->isAdmin())
		{
			return;
		}
        $varyKey = self::getVaryKey("Logout");
        $this->checkVary($varyKey);
        if($this->esiEnabled){
            $this->lscInstance->purgeAllPrivate();
        }
       
    }
    
    
    public function onUserAfterDelete($user,  $success, $msg){
        if(!$this->cacheEnabled){
            return;
        }

        if(!$success){
            return;
        }
        $this->purgeContent("com_users.user", $user);
        $this->log();
    }
    
    
    
    protected function purgeContent($context, $row)
    {
        $option = $this->getOption($context);
        
        if (in_array($context, self::CATEGORY_CONTEXTS)){
            $purgeTags = 'com_categories, com_categories:'.$row->id ; 
            if($row->parent_id){
                $purgeTags .= ',com_categories:'.$row->parent_id ; 
            }
            $this->lscInstance->purgePublic($purgeTags);
            return;
        }
        
        $menu_contexts = array('com_menus.item', 'com_menus.menu');
		if (in_array($context, $menu_contexts))
		{
            $purgeTags = 'com_menus,com_menus:'.$row->id ;
            if($row->menutype){
                $purgeTags .= ",com_menus:" . $row->menutype;
            }
            $menu = $row;
            while($menu && ($menu->level>1) && $menu->parent_id){
    			$purgeTags .= ',com_menus:'.$menu->parent_id;
                $menu = $this->app->getMenu()->getItem($menu->id);
            }
            
			$this->lscInstance->purgePublic($purgeTags);
            return;
		}

        if($this->isOptionExcluded($option)){
            return;
        }
        
		if (in_array($context, self::CONTENT_CONTEXTS)){
            $purgeTags=$option . ','. $option . ':' . $row->id;
            
            if($row->catid){
                $purgeTags .= ',com_categories:'.$row->catid;
            }
            $this->lscInstance->purgePublic($purgeTags);
            return;
        }

        if($context == "com_users.user"){
            $purgeTags='com_users,com_users:' . $row["id"];
            $this->lscInstance->purgePublic($purgeTags);
            return;
        }
        
        if($context == "com_k2.item"){
            $purgeTags='com_k2,com_k2:' . $row->id;
            $this->lscInstance->purgePublic($purgeTags);
            return;
        }

        if($row && $row instanceof JTable){
            $tableName = str_replace('#__', "DB", $row->getTableName());
            $purgeTags = $tableName . ':' . implode('-', $row->getPrimaryKey());
            $this->lscInstance->purgePublic($purgeTags);
            return;
        }

        $this->lscInstance->purgeAllPublic($option);    
    }

    
    public function onContentChangeState($context, $pks, $value)
    {
        if(!$this->cacheEnabled){
            return;
        }
        $option = $this->getOption($context);

        if($option=="com_plugins"){
            foreach($pks as $pk){
                $row->id = $pk;
                $row->enabled = $value; 
                $this->purgeExtension($context, $row);
            }
            $this->log();
            return;
        }

        if($option=="com_modules"){
            foreach($pks as $pk){
                $row->id = $pk;
                $this->purgeExtension($context, $row);
            }
            $this->log();
            
            return;
        }
        
        if($this->isOptionExcluded($option)){
            return;
        }

        foreach($pks as $pk){
            $row->id = $pk;
            $this->purgeContent($context, $row);
        }
        $this->log();
    }

    
    public function onExtensionAfterSave($context, $row, $isNew)
    {
        if(!$this->cacheEnabled){
            return;
        }
        
        $this->purgeExtension($context, $row);
        $this->log();
    }

    
    public function onExtensionBeforeDelete($context, $row)
    {
        if(!$this->cacheEnabled){
            return;
        }
        $this->purgeExtension($context, $row);
        $this->log();
    }

    
    protected function purgeExtension($context, $row){
        
        $option = $this->getOption($context);
        if($option == "com_plugins"){
            if($this->settings->get("autoPurgePlugin", 0)==1){
                $this->lscInstance->purgeAllPublic();
            }
            else if(!empty($row->element) && !empty($row->folder) && ($row->element=="lscache") && ($row->folder=="system")){
                $this->lscInstance->purgeAllPublic();
            }
            else if(!empty($row->id)){
                $extension = $this->getExtension($row->id);
                if(($extension->element=="lscache") && ($extension->folder=="system")){
                    $this->lscInstance->purgeAllPublic();
                }
            }
            return;
        }
        
        if($option == "com_languages"){
            if($this->settings->get("autoPurgeLanguage", 0)==1){
                $this->lscInstance->purgeAllPublic();
            }
            return;
        }
        
        if($context == "com_templates.style"){
            if($row->home){
                $this->lscInstance->purgeAllPublic();
                return;
            }

            $purgeTags = array();
            
            $db = JFactory::getDbo();

            $query = $db->getQuery(true)
                ->select('id')
                ->from('#__menu')
                ->where($db->quoteName('template_style_id') . '=' . (int)$row->id);
            $db->setQuery($query);

            $menus = $db->loadObjectList();
            
            foreach($menus as $menu){
                    $purgeTags[]= "com_menus:" . $menu->id; 
            }
            $this->lscInstance->purgePublic(implode(',', $purgeTags));
            return;
        }
        
        if($option == "com_modules"){
            $cacheType = $this->getModuleCacheType($row);

            if($cacheType==self::MODULE_PURGEALL){
                $this->lscInstance->purgeAllPublic();
                return;
            }
            
            $purgeTags = "com_modules:" . $row->id;
            
            if($cacheType==self::MODULE_PURGETAG){
                $db = JFactory::getDbo();
                $query = $db->getQuery(true)
                    ->select('menuid')
                    ->from('#__modules_menu')
                    ->where($db->quoteName('moduleid') . '=' . (int)$row->id);
                $db->setQuery($query);
                $menus = $db->loadObjectList();

                foreach($menus as $menu){
                        $purgeTags .= ",com_menus:" . $menu->menuid;
                }
            }
            
            $this->lscInstance->purgePublic($purgeTags);
            return;
        }
        
        if($context == "com_lscache.module"){
            $purgeTags = "com_modules:" . $row->moduleid;
            $this->lscInstance->purgePublic($purgeTags);
            return;
        }
        
        if($option == "com_config"){
            if($row->element == "com_lscache"){
                $settings = json_decode($row->params);
                $cacheEnabled = $settings->cacheEnabled;
                if(!$cacheEnabled){
                    $this->lscInstance->purgeAllPublic();
                }
            }
            else{
                $this->lscInstance->purgePublic($row->element);
            }
        }
    }

    
    protected function explode2($str, $d1, $d2){

        $result = array();
        
        $Parts = explode($d1, trim($str));
        foreach($Parts as $part1){
            list( $key, $val ) = explode($d2, trim($part1));
            $result[urldecode($key)]= urldecode($val);
        }
        
        return $result;
    }
    
    
    protected function implode2(array $arr, $d1, $d2){

        $arr1 = array();
        
        foreach($arr as $key=>$val){
            $arr1[] = urlencode($key) . $d2 . urlencode($val);
        }
        
        return implode($d1, $arr1);
    }
    
    
    public function onExtensionAfterInstall($installer, $eid)
    {
        if(!$this->cacheEnabled){
            return;
        }
        $this->purgeInstallation($eid,true);
        $this->log();
    }
    

    public function onExtensionAfterUpdate($installer, $eid)
    {
        if(!$this->cacheEnabled){
            return;
        }
        $this->purgeInstallation($eid);
        $this->log();
    }

    
    public function onExtensionBeforeUninstall($eid)
    {
        if(!$this->cacheEnabled){
            return;
        }
        
        $this->purgeInstallation($eid);
        $this->log();
    }

    
    protected function purgeInstallation($eid, $isNew = false){
        
        $extension = $this->getExtension($eid);
    
        if(!$extension){
            return;
        }
        
        if($isNew && in_array($extension->type, array('template','module','file','component'))){
            return;
        }
        
        if(in_array($extension->type, array('language','plugin'))){
            if(($extension->element=="lscache") && ($extension->folder=="system")){
                $this->lscInstance->purgeAllPublic();
                return;
            }
            $this->purgeExtension("com_" . $extension->type  . 's.extension', $extension);
            return;
        }
        
        if($extension->type=="component"){
            if($extension->element=="com_lscache"){
                $this->lscInstance->purgeAllPublic();
                return;
            }
            if(!$this->isOptionExcluded($extension->element)){
                $this->lscInstance->purgePublic($extension->element);
            }
            return;
        }
        
        if($extension->type=="template"){
            $template = $this->getTemplate($extension->element);{
                $this->purgeExtension("com_templates.style", $template);
            }
            return;
        }
        
        if($extension->type=="module"){
            $modules = $this->getModules($extension->element);
            foreach($modules as $module){
                $this->purgeExtension("com_modules.module", $module);
            }
            return;
        }
    }
    
    
    
    private function getModule($moduleid){
        
		$db = \JFactory::getDbo();
		$query = $db->getQuery(true)
			->select('*')
			->from('#__modules')
            ->where('id='. $moduleid);
		$db->setQuery($query);
        $modules =  $db->loadObjectList();
        if(count($modules)<1){
            return FALSE;
        }
        else{
            return $modules[0];
        }
    }

    
    private function getModuleCacheType($module){
        
        $db = JFactory::getDbo();

        if(!empty($module->cache_type)){
            return $module->cache_type;
        }

        $query = $db->getQuery(true)
            ->select('*')
            ->from('#__modules_lscache')
            ->where($db->quoteName('moduleid') . '=' . (int)$module->id);
        $db->setQuery($query);
        $rows =  $db->loadObjectList();
        if(count($rows)>0){
            $module->cache_type = self::MODULE_ESI;
            $module->lscache_type = $rows[0]->lscache_type;
            $module->lscache_ttl = $rows[0]->lscache_ttl;
            return self::MODULE_ESI;
        }
        
        $query1 = $db->getQuery(true)
            ->select('MIN(menuid)')
            ->from('#__modules_menu')
            ->where($db->quoteName('moduleid') . '=' . (int)$module->id);
        $db->setQuery($query1);
        $pages =  (int) $db->loadResult();

        if($pages<=0){
            $module->cache_type = self::MODULE_PURGEALL;
            return self::MODULE_PURGEALL;
        }

        $module->cache_type = self::MODULE_PURGETAG;
        return self::MODULE_PURGETAG;
    }
  

    
    protected function getModules($element){
       
		$db = JFactory::getDbo();

		$query = $db->getQuery(true)
			->select('*')
			->from('#__modules')
			->where($db->quoteName('module') . '="' . $db->$element . '"');
		$db->setQuery($query);
        
		$modules =  $db->loadObjectList();
        return $modules;
    }

    
    protected function getExtension($eid){
       
		$db = JFactory::getDbo();

		$query = $db->getQuery(true)
			->select('*')
			->from('#__extensions')
			->where($db->quoteName('extension_id') . '=' . (int)$eid);
		$db->setQuery($query);
        
		$extension =  $db->loadObjectList();
        
        if(count($extension)>0){
            return $extension[0];
        }
        else{
            return null;
        }
    }

    
    /**
     * a simple debug function only for development usage
     *
     * @since    0.1
     */
    private function debug($action)
    {
        $debugFile = "lscache.log";

        date_default_timezone_set("America/New_York");
        list( $usec, $sec ) = explode(' ', microtime());

        if (!defined("LOG_INIT")) {
            define("LOG_INIT", true);
            file_put_contents($debugFile, "\n\n" . date('m/d/y H:i:s') . substr($usec, 1, 4), FILE_APPEND);
        }
        file_put_contents($debugFile, date('m/d/y H:i:s') . substr($usec, 1, 4) . "\t" . $action . "\n", FILE_APPEND);
    }
    
    /**
     * log if logLevel below settings
     *
     * @since    0.1
     */
    protected function log($content=null, $logLevel = JLOG::INFO)
    {
        if($content==null){
            if(!$this->lscInstance){
                return;
            }
            $purgeMessage =  $this->settings->get('purgeMessage', 1) == 1 ? true : false;
            if(!$purgeMessage){
                return;
            }
            
            $content = $this->lscInstance->getLogBuffer();
            if(stripos($content, 'X-LiteSpeed-Purge') !== false){
                $this->app->enqueueMessage("Purged LiteSpeedCache with tags : " . str_replace('X-LiteSpeed-Purge:', "", $content) , "LiteSpeedCache");
            }
        }
        
        //$this->debug($content);
        
        $logLevelSetting = $this->settings->get('logLevel', -1);
        if ($logLevelSetting < 0) {
            return;
        }

        if ($logLevel > $logLevelSetting) {
            return;
        }

        JLog::add($content, $logLevel, 'LiteSpeedCache Plugin');
    }
    
    
    protected function isOptionExcluded($option)
    {
        $excludeOptions = $this->settings->get('excludeOptions', array());
        if ($excludeOptions && $option && in_array($option, (array)$excludeOptions)) {
            return true;
        }
        return false;
    }

    /**
     * Check if the page is excluded from the cache or not.
     *
     * @return   boolean  True if the page is excluded else false
     *
     * @since    0.1
     */
    
    protected function isExcluded()
    {
        $option = $this->pageElements["option"];
        if($option && $this->isOptionExcluded($option)){
            return true;
        }
        
        $excludeMenuItems = $this->settings->get('excludeMenus', array());
        if ($excludeMenuItems) {
            $menuItem = $this->menuItem;
            if ($menuItem && $menuItem->id && $excludeMenuItems && in_array($menuItem->id, (array) $excludeMenuItems, true)) {
                return true;
            }
        }

        // Check if regular expressions are being used
        $excludeURIs = $this->settings->get('excludeURLs', '');
        if (!$excludeURIs) {
            return false;
        }
        
        $exclusions = explode("\n", str_replace(array("\r\n", "\r"), "\n", $excludeURIs));
        if (!$exclusions) {
            return false;
        }

        $path = JUri::getInstance()->toString(array('path', 'query', 'fragment'));
        foreach ($exclusions as $exclusion) {
            if ($exclusion == '') {
                continue;
            }
            
            if((strpos($exclusion,'/')!==FALSE) && (strpos($exclusion,'\/')===FALSE)){
                $exclusion = str_replace('/', '\/', $exclusion);
            }
            
            if (preg_match('/' . $exclusion . '/is', $path)) {
                return true;
            }
        }
        return false;
    }
    
        

    protected function isLoginExcluded()
    {
        $excludeMenuItems = $this->settings->get('loginExcludeMenus', array());
        if ($excludeMenuItems) {
            $menuItem = $this->menuItem;
            if ($menuItem && $menuItem->id && $excludeMenuItems && in_array($menuItem->id, (array) $excludeMenuItems, true)) {
                return true;
            }
        }

        // Check if regular expressions are being used
        $excludeURIs = $this->settings->get('loginExcludeURLs', '');
        if (!$excludeURIs) {
            return false;
        }
        
        $exclusions = explode("\n", str_replace(array("\r\n", "\r"), "\n", $excludeURIs));
        if (!$exclusions) {
            return false;
        }

        $path = JUri::getInstance()->toString(array('path', 'query', 'fragment'));
        foreach ($exclusions as $exclusion) {
            if ($exclusion == '') {
                continue;
            }
            
            if((strpos($exclusion,'/')!==FALSE) && (strpos($exclusion,'\/')===FALSE)){
                $exclusion = str_replace('/', '\/', $exclusion);
            }
            
            if (preg_match('/' . $exclusion . '/is', $path)) {
                return true;
            }
        }
        return false;
    }
    
    
    //ESI Render;
    public function onAfterInitialise()
    {
        $app = $this->app;
        if ($app->isAdmin())
		{
			return;
		}
        
        $option = $app->input->get('option');
        if($option!="com_lscache"){
            return;
        }
        
        $cleancache = $app->input->get('cleancache');
        if($cleancache!=null){
            $cleanWords = $this->settings->get('cleanCache', 'purgeAllCache');
            if($cleancache==$cleanWords){
                $this->lscInstance->purgeAllPublic();
                echo "<html><body><h2>All LiteSpeed Cache Purged!</h2></body></html>";
            }
            else{
                http_response_code(403);
            }
            $app->close();
            return;
        }
        
        if(!$this->esiEnabled){
            return;
        }
        
        if(LITESPEED_ESI_SUPPORT===FALSE){
            return;
        }
        
        $moduleid = $this->app->input->getInt('moduleid', -1);
        if($moduleid==-1){
            http_response_code(403);
			$app->close();
            return;
        }
        
        $module = $this->getModule($moduleid);
        if(!$module){
            http_response_code(403);
			$app->close();
            return;
        }
        
        $cacheType = $this->getModuleCacheType($module);
        if($cacheType!=self::MODULE_ESI){
            http_response_code(403);
			$app->close();
            return;
        }
        
        $attribs = array();
        $attrib =  $_GET['attribs'];
        if($attrib){
            $attribs = $this->explode2($attrib, ';', ',');
        }
        
        $content =  JModuleHelper::renderModule($module, $attribs);
        if($content){
            $tag = "com_modules:" . $module->id;
            $cacheTimeout = $module->lscache_ttl * 60;
            $this->lscInstance->config(array("public_cache_timeout"=>$cacheTimeout, "private_cache_timeout"=>$cacheTimeout));
            if($module->lscache_type==1){
                $this->lscInstance->cachePublic($tag);
            }
            else if($module->lscache_type==-1){
                $this->lscInstance->cachePrivate($tag);
            }
            
			$app->setBody($content);
    		echo $app->toString();
			$app->close();
        }
        
    }
    
    
    private function getModuleAttribs(array $attribs){
        if($attribs && count($attribs)>0){
            $attrib = $this->implode2($attribs,';',',');
            $result =  '&attribs=' .  $attrib;
            return $result;
        }
        return '';
    }
    

    private function getVaryKey($loginStatus)
    {
        //$lang = JFactory::getLanguage();
        
        $defaultVary= 'desktopLogout' ; //. $lang->getDefault();
        $device = "desktop";
        
        if($this->app->client->mobile){
            $device = 'mobile';
        }
        $varyKey = $device . $loginStatus; // . $lang->getTag();
        
        if($varyKey==$defaultVary){
            return '';
        }
        return $varyKey;
    }
    

    /**
     *
     *  set or delete cache vary cookie, if cookie need no change return true;
     *
     * @since   0.1
     */
    private function checkVary($value)
    {
        
        $inputCookie  = $this->app->input->cookie;

        if ($value == "") {
            if ($inputCookie->get(LiteSpeedCacheBase::VARY_COOKIE)!==null) {
                $inputCookie->set(LiteSpeedCacheBase::VARY_COOKIE, null, time()-1);
                return false;
            }
            return true;
        }
        
        if($inputCookie->get(LiteSpeedCacheBase::VARY_COOKIE)==null){
            $inputCookie->set(LiteSpeedCacheBase::VARY_COOKIE, $value, 0);
            return false;
        }

        if($inputCookie->get(LiteSpeedCacheBase::VARY_COOKIE)!=$value){
            $inputCookie->set(LiteSpeedCacheBase::VARY_COOKIE, $value, 0);
            return false;
        }
        
        return true;
    }
    
}
