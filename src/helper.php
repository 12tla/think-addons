<?php
// +----------------------------------------------------------------------
// | thinkphp5.1 Addons
// +----------------------------------------------------------------------
// | Copyright (c) 2019 https://www.12tla.com All rights reserved.
// +----------------------------------------------------------------------
// | Licensed ( http://www.apache.org/licenses/LICENSE-2.0 )
// +----------------------------------------------------------------------
// | Author: Byron Sampson <xiaobo.sun@qq.com>
// +----------------------------------------------------------------------
// | Sort：TwelveT <2471835953@qq.com>
// +----------------------------------------------------------------------

use think\facade\App;
use think\facade\Env;
use think\facade\Hook;
use think\facade\Config;
use think\Loader;
use think\facade\Cache;
use think\facade\Route;
use think\exception\HttpException;

//定义插件目录
$appPath = App::getAppPath();
$addons_path = dirname($appPath) . DIRECTORY_SEPARATOR . 'addons' . DIRECTORY_SEPARATOR;
Env::set('ADDONS_PATH', $addons_path);

//判断是否存在目录
if(!is_dir($addons_path)){
    @mkdir($addons_path, 0777, true);
}

// 插件访问路由配置
Route::group('addons', function () {
    if (!isset($_SERVER['REQUEST_URI'])) {
        return 'error addons';
    }
    // 获取请求地址
    $path = ltrim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');
    //获后缀
    $ext = pathinfo($path, PATHINFO_EXTENSION);
    //判断是否有后缀，有则去除后缀
    if ($ext) {
        $path = substr($path, 0, strlen($path) - (strlen($ext) + 1));
    }
    //以/分割参数
    $pathinfo = explode('/', $path);
    // 判断是否存在操作方法
    if (isset($pathinfo[3]) && $pathinfo[0] == 'addons') {
        //设置模块
        $module = $pathinfo[1];
        //定义文件路径
        $info_path = Env::get('ADDONS_PATH') . $module . DIRECTORY_SEPARATOR . 'info.ini';
        //判断此路径是否存在配置文件
        if (!is_file($info_path)) throw new HttpException(404, '不存在此插件');
        //获取信息
        $info = parse_ini_file($info_path);
        //判断插件是否被禁用
        if (!$info['state']) throw new HttpException(500, '插件已被禁止使用');
        //设置控制器
        $controller = ucfirst($pathinfo[2]);
        //设置类型
        $type = ucfirst($pathinfo[2]);
        // 生成view_path
        $view_path = Env::get('addons_path') . $module . DIRECTORY_SEPARATOR . 'view' . DIRECTORY_SEPARATOR;
        //设置地址
        Config::set('template.view_path', $view_path);
        // 中间件
        $middleware = [];
        $config = Config::get('addons.middleware');
        if (is_array($config) && isset($config[$type])) {
            $middleware = (array)$config[$type];
        }
        // 请求转入
        Route::rule(':rule', "\\addons\\{$module}\\controller\\{$controller}@{$pathinfo[3]}")
            ->middleware($middleware);
    }
})->middleware(function ($request, \Closure $next) {
    // 获取参数
    $pathinfo = explode('/', $request->path());
    //设置方法
    $request->setModule($pathinfo[2]);
    //设置模块
    $request->setController($pathinfo[2]);
    //设置方法
    $request->setAction($pathinfo[3]);
    return $next($request);
});

// 注册类的根命名空间
Loader::addNamespace('addons', $addons_path);

// 闭包自动识别插件目录配置
Hook::add('app_init', function () {
    // 获取开关
    $autoload = (bool)Config::get('addons.autoload', false);
    // 非正是返回
    if (!$autoload) {
        return;
    }
    // 当debug时不缓存配置
    $config = App::isDebug() ? [] : cache('addons');
    if (empty($config)) {
        // 读取插件目录及钩子列表
        $base = get_class_methods("\\think\\Addons");
        // 读取插件目录中的php文件
        foreach (glob(Env::get('addons_path') . '*/*.php') as $addons_file) {
            // 格式化路径信息
            $info = pathinfo($addons_file);
            // 获取插件目录名
            $name = pathinfo($info['dirname'], PATHINFO_FILENAME);
            // 找到插件入口文件
            if (strtolower($info['filename']) == 'widget') {
                // 读取出所有公共方法
                $methods = (array)get_class_methods("\\addons\\" . $name . "\\" . $info['filename']);
                // 跟插件基类方法做比对，得到差异结果
                $hooks = array_diff($methods, $base);
                // 循环将钩子方法写入配置中
                foreach ($hooks as $hook) {
                    if (!isset($config['hooks'][$hook])) {
                        $config['hooks'][$hook] = [];
                    }
                    // 兼容手动配置项
                    if (is_string($config['hooks'][$hook])) {
                        $config['hooks'][$hook] = explode(',', $config['hooks'][$hook]);
                    }
                    if (!in_array($name, $config['hooks'][$hook])) {
                        $config['hooks'][$hook][] = $name;
                    }
                }
            }
        }
        cache('addons', $config);
    }
    config('addons', $config);
});

// 闭包初始化行为
Hook::add('action_begin', function () {
    // 获取系统配置
    $data = App::isDebug() ? [] : Cache::get('hooks', []);
    $config = config('addons');
    $addons = isset($config['hooks']) ? $config['hooks'] : [];
    if (empty($data)) {
        // 初始化钩子
        foreach ($addons as $key => $values) {
            if (is_string($values)) {
                $values = explode(',', $values);
            } else {
                $values = (array)$values;
            }
            $addons[$key] = array_filter(array_map('get_addons_class', $values));
            Hook::add($key, $addons[$key]);
        }
        cache('hooks', $addons);
    } else {
        Hook::import($data, false);
    }
});

/**
 * 监听并处理钩子行为
 * @param string $hook 钩子名称
 * @param mixed $params 传入参数
 * @return void
 */
function hook($hook, $params = [])
{
    $result = Hook::listen($hook, $params);
    if (is_array($result)) {
        $result = join(PHP_EOL, $result);
    }
    return $result;
}

if (!function_exists('get_addons_class')) {
    /**
     * 获取插件类的类名
     * @param $name 插件名
     * @param string $type 返回命名空间类型
     * @param string $class 当前类名
     * @return string
     */
    function get_addons_class($name, $type = 'hook', $class = null)
    {
        $name = Loader::parseName($name);
        // 处理多级控制器情况
        if (!is_null($class) && strpos($class, '.')) {
            $class = explode('.', $class);
            foreach ($class as $key => $cls) {
                $class[$key] = Loader::parseName($cls, 1);
            }
            $class = implode('\\', $class);
        } else {
            $class = Loader::parseName(is_null($class) ? $name : $class, 1);
        }
        switch ($type) {
            case 'controller':
                $namespace = "\\addons\\" . $name . "\\controller\\" . $class;
                break;
            default:
                $namespace = "\\addons\\" . $name . "\\" . $class;
        }

        return class_exists($namespace) ? $namespace : '';
    }
}

if (!function_exists('get_addons_config')) {
    /**
     * 获取插件类的配置文件数组
     * @param string $name 插件名
     * @param string $parse 是否需要解析
     * @return array
     */
    function get_addons_config($name, $parse = false)
    {
        static $_config = array();

        // 获取当前插件目录
        $addons_path = Env::get('addons_path') . $name . DIRECTORY_SEPARATOR;
        // 读取当前插件配置信息
        if (is_file($addons_path . 'config.php')) {
            $config_file = $addons_path . 'config.php';
        }

        if (isset($_config[$name])) {
            return $_config[$name];
        }

        $config = [];
        if (isset($config_file) && is_file($config_file)) {
            //引入数组
            $temp_arr = include $config_file;
            if (is_array($temp_arr)) {
                if ($parse) {
                    foreach ($temp_arr as $key => $value) {
                        if ($value['type'] == 'group') {
                            foreach ($value['options'] as $gkey => $gvalue) {
                                foreach ($gvalue['options'] as $ikey => $ivalue) {
                                    $config[$ikey] = $ivalue['value'];
                                }
                            }
                        } else {
                            $config[$key] = $temp_arr[$key]['value'];
                        }
                    }
                } else {
                    $config = $temp_arr;
                }
            }
            unset($temp_arr);
        }
        $_config[$name] = $config;

        return $config;
    }
}

if (!function_exists('get_addons_info')) {
    
    /**
     * 获取插件信息
     * @param $name
     * @return array
     */
    function get_addons_info($name)
    {
        //获取插件
        $addon = get_addons_instance($name);
        if (!$addon) {
            return [];
        }
        //返回插件配置信息
        return $addon->getInfo();
    }
}


if (!function_exists('addons_url')) {
    /**
     * 插件显示内容里生成访问插件的url
     * @param $url
     * @param array $param
     * @param bool|string $suffix 生成的URL后缀
     * @param bool|string $domain 域名
     * @return bool|string
     */
    function addons_url($url, $vars = [], $suffix = true, $domain = false)
    {
        $url = ltrim($url, '/');
        $addon = substr($url, 0, stripos($url, '/'));
        if (!is_array($vars)) {
            parse_str($vars, $params);
            $vars = $params;
        }
        $params = [];
        foreach ($vars as $k => $v) {
            if (substr($k, 0, 1) === ':') {
                $params[$k] = $v;
                unset($vars[$k]);
            }
        }
        $val = "@addons/{$url}";
        $config = get_addons_config($addon);
        $dispatch = think\facade\Request::instance()->dispatch();
        $indomain = true;
        $domainprefix = $config && isset($config['domain']) && $config['domain'] ? $config['domain'] : '';
        $rewrite = $config && isset($config['rewrite']) && $config['rewrite'] ? $config['rewrite'] : [];
        if ($rewrite) {
            $path = substr($url, stripos($url, '/') + 1);
            if (isset($rewrite[$path]) && $rewrite[$path]) {
                $val = $rewrite[$path];
                array_walk($params, function ($value, $key) use (&$val) {
                    $val = str_replace("[{$key}]", $value, $val);
                });
                $val = str_replace(['^', '$'], '', $val);
                if (substr($val, -1) === '/') {
                    $suffix = false;
                }
            } else {
                // 如果采用了域名部署,则需要去掉前两段
                if ($indomain && $domainprefix) {
                    $arr = explode("/", $val);
                    $val = implode("/", array_slice($arr, 2));
                }
            }
        } else {
            // 如果采用了域名部署,则需要去掉前两段
            if ($indomain && $domainprefix) {
                $arr = explode("/", $val);
                $val = implode("/", array_slice($arr, 2));
            }
            foreach ($params as $k => $v) {
                $vars[substr($k, 1)] = $v;
            }
        }
        return url($val, [], $suffix, $domain) . ($vars ? '?' . http_build_query($vars) : '');
    }
}

#############################################################################

if(!function_exists('get_addons_list')){
    /**
     * 获得插件列表
     * @return array
     */
    function get_addons_list()
    {
        //获取插件目录
        $addons_path = Env::get('addons_path');
        //获取插件目录
        $results = scandir($addons_path);
        $list = [];
        foreach ($results as $name) {
            //判断是否存在插件目录
            if ($name === '.' or $name === '..')    continue;
            //判断是否为插件目录
            if (is_file($addons_path . $name))      continue;
            //插件目录地址
            $addonDir = $addons_path . $name . DIRECTORY_SEPARATOR;
            //检查是否为目录
            if (!is_dir($addonDir)) continue;
            //判断插件目录下的主要文件是否为PHP源代码
            if (!is_file($addonDir . ucfirst($name) . '.php')) continue;
            //插件配置文件地址
            $info_file = $addonDir . 'info.ini';
            //判断是否为文件
            if (!is_file($info_file)) continue;
            //解析文件信息
            $info = Config::parse($info_file, '', "addon-info-{$name}");
            //获取插件地址
            $info['url'] = addons_url($name);
            //最终信息数组
            $list[$name] = $info;
        }
        return $list;
    }
}

if(!function_exists('get_addons_instance')){
    /**
     * 获取插件的单例
     * @param $name
     * @return mixed|null
     */
    function get_addons_instance($name)
    {
        //定义数组
        static $_addons = [];
        //判断是否设置参数
        if (isset($_addons[$name])) {
            return $_addons[$name];
        }
        //得到插件类命名空间
        $class = get_addons_class($name);
        if (class_exists($class)) {
            $_addons[$name] = new $class();
            //返回插件类名称
            return $_addons[$name];
        } else {
            return null;
        }
    }
}

if (!function_exists('is_really_writable')) {

    /**
     * 判断文件或文件夹是否可写
     * @param    string $file 文件或目录
     * @return    bool
     */
    function is_really_writable($file)
    {
        if (DIRECTORY_SEPARATOR === '/') {
            return is_writable($file);
        }
        if (is_dir($file)) {
            $file = rtrim($file, '/') . '/' . md5(mt_rand());
            if (($fp = @fopen($file, 'ab')) === false) {
                return false;
            }
            fclose($fp);
            @chmod($file, 0777);
            @unlink($file);
            return true;
        } elseif (!is_file($file) or ($fp = @fopen($file, 'ab')) === false) {
            return false;
        }
        fclose($fp);
        return true;
    }
}

if(!function_exists('set_addons_fullconfig')){
    /**
     * 写入配置文件
     *
     * @param string $name 插件名
     * @param array $array
     * @return boolean
     * @throws Exception
     */
    function set_addons_fullconfig($name, $array)
    {
        $file = Env::get('ADDONS_PATH') . $name . DIRECTORY_SEPARATOR . 'config.php';
        if (!is_really_writable($file)) {
            throw new Exception("文件没有写入权限");
        }
        if ($handle = fopen($file, 'w')) {
            fwrite($handle, "<?php\n\n" . "return " . var_export($array, TRUE) . ";\n");
            fclose($handle);
        } else {
            throw new Exception("文件没有写入权限");
        }
        return true;
    }
}

if(!function_exists('set_addons_info')){
    /**
     * 设置插件信息
     * @param bool|string $name 插件名称
     * @param bool|string $info 需要设置的插件信息【数组】
     */
    function set_addons_info($name, $info)
    {
        //定义插件配置信息地址
        $address = Env::get('addons_path') . $name . DIRECTORY_SEPARATOR . 'info.ini';
        //获取插件实例
        $addon = get_addons_instance($name);
        //设置插件信息
        $info = $addon->setInfo($name, $info);
        //定义结果数组
        $result = [];
        //遍历数组
        foreach ($info as $key => $val) {
            //判断是否为数组
            if (is_array($val)) {
                //设置键值为值
                $result[] = "[$key]";
                //再次遍历数组
                foreach ($val as $skey => $sval){
                    //存进数组
                    $result[] = "$skey = " . (is_numeric($sval) ? $sval : $sval);
                }
            } else{
                //直接存在数组
                $result[] = "$key = " . (is_numeric($val) ? $val : $val);
            }
        }
        //尝试设置信息
        try{
            //打开插件配置文件
            $file_ini = fopen($address, 'w');
             //打散数组换行，写入信息
             fwrite($file_ini, implode("\n", $result) . "\n");
             //关闭资源
             fclose($file_ini);
             //清空当前配置缓存
             Config::set($name, NULL, 'addoninfo');
        }catch(Exception $e){
            throw new Exception($e->getMessage());
        }
        return true;
    }
}

if (!function_exists('copyDirs')) {
    /**
     * 复制文件夹
     * @param string $source 源文件夹
     * @param string $target   目标文件夹
     */
    function copyDirs($source, $target)
    {
        //判断目标目录是否存在，不存在创建
        if (!is_dir($target)) {
            mkdir($target, 0755, true);
        }
        //递归计算文件
        $addonDirs = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($source, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        //遍历文件
        foreach ($addonDirs as $addonDir) {
            //判断是否为目录
            if ($addonDir->isDir()) {
                //是目录创建目录
                $sontDir = $target . DIRECTORY_SEPARATOR . $addonDirs->getSubPathName();
                if (!is_dir($sontDir)) {
                    mkdir($sontDir, 0755, true);
                }
            } else {
                //是文件直接复制移动
                copy($addonDir, $target . DIRECTORY_SEPARATOR . $addonDirs->getSubPathName());
            }
        }
    }
}
