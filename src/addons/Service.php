<?php

namespace think\addons;

use think\facade\Env;
use think\Exception;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ZipArchive;
use think\Db;

/**
 * 插件服务
 * @package think\addons
 */
class Service
{
    //定义需要检测冲突的目录
    private static $checkDirs = ['twelvet','public'];
    /**
     * SQL导入
     *
     * @param   string $config 插件配置信息
     */
    public static function importsql($config)
    {
        //定义sql位置
        $sqlFile = Env::get('ADDONS_PATH') . $config['name'] . DIRECTORY_SEPARATOR . 'install.sql';
        //判断是否存在文件，以及数据表信息是否存在
        if (is_file($sqlFile) && !empty($config['db_tables'])) {
            //以每行为单位组成数组读取
            $lines = file($sqlFile);
            $tempSql = '';
            try{
                //定义数据表开始标签
                $table_begin = '{::';
                //定义数据表结束标签
                $table_end   = '::}';
                //定义数据库表名获取正则
                $regex = '/' . $table_begin . '[\s\S]*' . $table_end . '/';
                //打散字符串
                $db_tables = explode(',', $config['db_tables']);
                //获取数据表前缀
                $prefix = config('database.prefix');
                //获取系统核心数据表
                $db_cores = config('twelvet.db_tables');
                //定义允许的数据指令
                $commands = ['create table', 'insert into'];
                //定义执行的第几段数据
                $numSql = 0;
                //遍历执行SQL
                foreach ($lines as $line) {
                    //Mysql注释、空行一律跳过
                    if (substr($line, 0, 2) == '--' || substr($line, 0, 2) == '/*' || substr($line, 0, 2) == '#' || $line == "\n") {
                        continue;
                    }
                    //拼接SQL
                    $tempSql .= $line;
                    //获取每一行最后一个字符串判断是否到达SQL一句结尾
                    if (substr(trim($line), -1, 1) == ';') {
                        //开始记录尝试执行的SQL
                        ++$numSql;
                        //根据标签获取数据表名称
                        preg_match($regex,$tempSql,$sqlNameTmp);
                        //去除标签获得表名
                        $sqlName = trim(str_replace([$table_begin, $table_end] , '', $sqlNameTmp[0]));
                        //获取即将执行的命令核心
                        $commandTmp = trim(substr(strtolower($tempSql), 0, 12));
                        //检索即将执行的SQL表名是否存在配置信息中
                        if(!in_array($sqlName, $db_tables)){
                            throw new Exception('执行的命令不存在插件配置信息中SQL：'. $sqlName .'，已被系统终止安装！');
                        }
                        //检索是否在允许执行的命令中
                        if(!in_array($commandTmp, $commands)){
                            throw new Exception('插件包正在尝试执行系统禁止的SQL：'. $sqlName .'，已被系统终止安装！');
                        }
                        //检索表名是否与系统核心表冲突 
                        if(in_array($sqlName, $db_cores)){
                            throw new Exception('插件包正在尝试操作系统核心数据表，SQL：'. $sqlName .'，已被系统终止安装！');
                        }
                        //替换表名前缀
                        $sqlName = str_ireplace('__PREFIX__', $prefix, $sqlName);
                        //替换最终的数据表表名
                        $tempSql = preg_replace($regex, $sqlName, $tempSql);
                        //如存在相同的数据将忽略插入
                        $tempSql = str_ireplace('INSERT INTO ', 'INSERT IGNORE INTO ', $tempSql);
                        //执行SQL
                        Db::execute($tempSql);
                        //初始化SQL
                        $tempSql = '';
                    }
                }
            }catch (\Exception $e) {
                //事务逻辑回滚
                foreach($db_tables as $key => $db_table){
                    //替换表名前缀
                    $delSqlName = str_ireplace('__PREFIX__', $prefix, $db_table);
                    //执行删除数据表指令
                    Db::execute('DROP TABLE IF EXISTS ' . $delSqlName);
                }
                //抛出异常
                throw new Exception('导入第'. $numSql .'条数据时发生错误：' . $e->getMessage());
            }
        }
        return true;
    }

    /**
     * 解压插件
     *
     * @param   string $name 插件名称
     * @return  string
     * @throws  Exception
     */
    public static function unPackage($name)
    {
        //获取插件包
        $file = Env::get('runtime_path') . 'addons' . DIRECTORY_SEPARATOR . $name . '.zip';
        //定义解压目录
        $dir = Env::get('ADDONS_PATH') . $name . DIRECTORY_SEPARATOR;
        if (class_exists('ZipArchive')) {
            $zip = new ZipArchive;
            //打开压缩包
            if ($zip->open($file) !== TRUE) {
                throw new Exception('无法打开压缩包');
            }
            //开始解压包
            if (!$zip->extractTo($dir)) {
                $zip->close();
                throw new Exception('无法提取文件');
            }
            //关闭资源并返回
            $zip->close();
            return $dir;
        }
        throw new Exception("无法执行解压操作，请确保ZipArchive安装正确");
    }

    /**
     * 卸载插件
     *
     * @param   string $name
     * @param   boolean $force 是否强制卸载
     * @return  boolean
     * @throws  Exception
     */
    public static function uninstall($name, $force = false)
    {
        //判断是否存在插件
        if (!$name || !is_dir(Env::get('ADDONS_PATH') . $name)) {
            throw new Exception('不存在此插件');
        }
        //判断是否有冲突
        if (!$force) Service::noconflict($name);
        // 移除插件基础资源目录
        $destAssetsDir = self::getRourceNameDir($name);
        if (is_dir($destAssetsDir)) {
            //执行删除
            rmdirs($destAssetsDir);
        }
        // 是否需要删除相同冲突文件
        if ($force) {
            $list = Service::getGlobalFiles($name);
            foreach ($list as $k => $v) {
                @unlink(ROOT_PATH . $v);
            }
        }
        // 执行卸载脚本
        try {
            $class = get_addons_class($name);
            if (class_exists($class)) {
                $addon = new $class();
                $addon->uninstall();
                // 移除插件目录
                rmdirs(Env::get('ADDONS_PATH') . $name);
            }
        } catch (Exception $e) {
            throw new Exception($e->getMessage());
        }
        
        return true;
    }

    /**
     * 启用插件
     * @param   string $name 插件名称
     * @param   boolean $force 是否强制覆盖
     * @return  boolean
     */
    public static function enable($name, $force = false)
    {
        //判断是否存在插件
        if (!$name || !is_dir(Env::get('ADDONS_PATH') . $name)) {
            throw new Exception('不存在此插件');
        }
        //判断是否有冲突
        if (!$force) self::noconflict($name);
        //定义插件目录
        $addonDir = Env::get('ADDONS_PATH') . $name . DIRECTORY_SEPARATOR;
        //获取插件静态资源目录
        $staticSourceDir = self::getStaticResourceDir($name);
        //获取此插件目录地址
        $sourceNameDir = self::getRourceNameDir($name);
        //判断是否为一个目录
        if (is_dir($staticSourceDir)) {
            //开始复制移动文件
            copyDirs($staticSourceDir, $sourceNameDir);
        }
        //遍历允许的插件目录
        foreach (self::$checkDirs as $k => $dir) {
            //判断是否存在目录
            if (is_dir($addonDir . $dir)) {
                //开始复制移动文件
                copyDirs($addonDir . $dir, Env::get('ROOT_PATH') . $dir);
            }
        }
        //执行启用脚本
        try {
            //获取插件类
            $class = get_addons_class($name);
            if (class_exists($class)) {
                //实例化
                $addon = new $class();
                //判断是否存在安装方法
                if (method_exists($class, "enable")) {
                    //开始执行作者的安装方法
                    $addon->enable();
                }
                //获取插件信息设置为禁用状态
                $info = get_addons_info($name);
                $info['state'] = 1;
                unset($info['url']);
                set_addons_info($name, $info);
            }
        } catch (Exception $e) {
            throw new Exception($e->getMessage());
        }
        
        return true;
    }

    /**
     * 禁用插件
     *
     * @param   string $name 插件名称
     * @param   boolean $force 是否强制禁用
     * @return  boolean
     * @throws  Exception
     */
    public static function disable($name, $force = false)
    {
        //判断是否存在插件
        if (!$name || !is_dir(Env::get('ADDONS_PATH') . $name)) {
            throw new Exception('不存在此插件');
        }
        //是否需要强制禁用
        if (!$force) Service::noconflict($name);
        // 获取目录
        $destAssetsDir = self::getRourceNameDir($name);
        //判断是否存在插件目录，存在删除
        if (is_dir($destAssetsDir)) {
            rmdirs($destAssetsDir);
        }
        // 获取插件全局资源文件
        $list = Service::getGlobalFiles($name);
        //遍历删除
        foreach ($list as $k => $v) {
            @unlink(Env::get('ADDONS_PATH') . $v);
        }
        // 执行禁用脚本
        try {
            //获取插件类，执行禁用
            $class = get_addons_class($name);
            if (class_exists($class)) {
                $addon = new $class();
                if (method_exists($class, "disable")) {
                    $addon->disable();
                }
                //获取插件信息设置为禁用状态
                $info = get_addons_info($name);
                $info['state'] = 0;
                unset($info['url']);
                set_addons_info($name, $info);
            }
        } catch (Exception $e) {
            throw new Exception($e->getMessage());
        }
        
        return true;
    }

    /**
     * 获取插件静态源资源文件夹
     * @param   string $name 插件名称
     * @return  string
     */
    protected static function getStaticResourceDir($name)
    {
        //返回静态资源文件目录地址
        return Env::get('ADDONS_PATH') . $name . DIRECTORY_SEPARATOR . 'addons' . DIRECTORY_SEPARATOR;
    }

    /**
     * 获取插件目标资源文件夹
     * @param   string $name 插件名称
     * @return  string
     */
    protected static function getRourceNameDir($name)
    {
        //定义插件静态资源准确位置
        $dir = Env::get('ROOT_PATH') . str_replace("/", DIRECTORY_SEPARATOR, "public/addons/{$name}/config/");
        //判断是否存在目录
        if (!is_dir($dir)) {
            //创建
            mkdir($dir, 0755, true);
        }
        //返回目录地址
        return $dir;
    }

    /**
     * 检测是否有冲突
     *
     * @param   string $name 插件名称
     * @return  boolean
     * @throws  AddonException
     */
    public static function noConflict($name)
    {
        // 检测冲突文件,默认检测冲突
        $conflictList = self::getGlobalFiles($name, true);
        if ($conflictList) {
            //发现冲突文件，抛出异常
            throw new AddonException("发现冲突文件", -3);
        }
        return true;
    }

    /**
     * 获取插件在全局的文件
     * @param   string $name 插件名称
     * @param   string $onlyConflict 扫描冲突文件【默认获取插件文件列表】
     * @return  array
     */
    public static function getGlobalFiles($name, $onlyConflict = false)
    {
        //定义列表
        $conflictList = [];
        //定义插件目录
        $addonDir = Env::get('ADDONS_PATH') . $name . DIRECTORY_SEPARATOR;
        // 遍历需要检测的目录,扫描插件目录是否有覆盖的文件
        foreach (self::$checkDirs as $k => $dir) {
            //定义检测目录
            $checkDir = Env::get('ROOT_PATH') . DIRECTORY_SEPARATOR . $dir . DIRECTORY_SEPARATOR;
            //判断是否为一个目录
            if (!is_dir($checkDir)) continue;
            //仅检索插件目录内是否存在允许目录
            if (is_dir($addonDir . $dir)) {
                //递归迭代器(递归目录，包括自身目录)
                $files = new RecursiveIteratorIterator(
                    //获取目录，跳过\.  \..   包括文件夹自身也要遍历
                    new RecursiveDirectoryIterator($addonDir . $dir, RecursiveDirectoryIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST
                );
                //开始遍历检测目录文件
                foreach ($files as $fileInfo) {
                    //判断是否为文件
                    if ($fileInfo->isFile()) {
                        //获取文件路径
                        $filePath = $fileInfo->getPathName();
                        //去除插件目录，保留需检测目录路径
                        $fileDir = str_replace($addonDir, '', $filePath);
                        //判断是否需要检测是否与系统文件冲突
                        if ($onlyConflict) {
                            //定义系统中的文件目录
                            $destPath = Env::get('ROOT_PATH') . $fileDir;
                            //判断系统目录中是否存在此文件，存在则冲突
                            if (is_file($destPath)) {
                                //判断文件是否完全一致
                                if (filesize($filePath) != filesize($destPath) || md5_file($filePath) != md5_file($destPath)) {
                                    //不一致则判定存在冲突，存进数组
                                    $conflictList[] = $fileDir;
                                }
                            }
                        } else {
                            //直接存进冲突数组
                            $conflictList[] = $fileDir;
                        }
                    }
                }
            }
        }
        return $conflictList;
    }
}
