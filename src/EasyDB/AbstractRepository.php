<?php

namespace EasyDB;

use Database\Connection;
use Database\Query\Builder;
use EasyCache\Cache;

abstract class AbstractRepository implements RepositoryInterface
{

    public static $table_name;
    public static $primary_key;
    private static $_instances = array();
    /**
     * @var Connection
     */
    private static $sconnection;
    /**
     * @var Cache
     */
    private static $scache;

    protected $table;
    protected $key;
    /**
     * @var Builder
     */
    protected $querybuild;

    protected $repo = false;
    protected $entities_id = [];
    protected $entities;

    public $relations;
    public $entity;

    /**
     * @var Cache
     */
    protected $cache;
    protected $last_id;

    protected $no_multiple_query = [
        'offset',
        'limit'
    ];
    protected $search_multiple = true;

    public function __construct()
    {
        $this->table = static::$table_name;
        $this->key = static::$primary_key;

        //$this->getConnection() = $db;
        //$this->getsCache() = $cache;
    }

    /**
     * @param Connection $connection
     */
    public static function setConnection(Connection $connection)
    {
        self::$sconnection = $connection;
    }

    /**
     * @param Cache $cache
     */
    public static function setsCache(Cache $cache)
    {
        self::$scache = $cache;
    }

    /**
     * @return Connection
     */
    private function getConnection()
    {
        return self::$sconnection;
    }

    /**
     * @return Cache
     */
    private function getsCache()
    {
        return self::$scache;
    }

    /**
     * @return array
     */
    public function scopes()
    {
        return [];
    }

    /**
     * @param string $as
     * @return string
     */
    public function getTable($as = '')
    {
        if ($as) {
            $as = ' as ' . $as;
        }

        return $this->table . $as;
    }

    /**
     * @param string $as
     * @return \Database\Query\Builder
     */
    public function query($as = '')
    {
        return $this->getConnection()->table($this->getTable($as));
    }

    protected function restoreCache(RepositoryAbstract $relation = null)
    {
        if ($relation) {
            $this->getsCache()->deleteByPrefix($relation::$table_name);
        } else {
            $this->getsCache()->deleteByPrefix($this->table);
        }
    }

    /**
     * @param $name
     * @return bool
     */
    protected function getCache($name)
    {

        if ($this->getsCache()->issetCache($name)) {
            return $this->getsCache()->get($name);
        } else {
            return false;
        }
    }

    /**
     * @param $name
     * @param $data
     * @return bool
     */
    protected function setCache($name, $data)
    {
        $this->getsCache()->set($name, $data);
        return $this->getCache($name);
    }

    /**
     * @param $relation
     * @return string
     */
    protected function getShortName($relation)
    {
        $d = new \ReflectionClass($relation);
        return $d->getShortName();
    }

    /**
     * @param $relation
     * @param $type
     * @param $sql
     * @return string
     */
    protected function getNameCache($relation, $type, $sql)
    {
        if ($relation) {
            $name = $relation::$table_name . '/';
        } else {
            $name = $this->table . '/';
        }
        $name .= hash('sha256', $type . $sql);

        return $name;
    }

    /**
     * @return array
     */
    protected function getInfoQuery()
    {

        $query = (!empty($this->querybuild)) ? $this->querybuild : $this->query();

        if (!$this->search_multiple) {
            $field = $this->repo['field'];
            $query->whereIn($field, array($this->last_id));
        }

        $info = [
            'lastid'   => $this->last_id,
            'query'    => $query,
            'relation' => $this->repo
        ];

        $this->last_id = false;
        $this->querybuild = '';
        $this->repo = false;
        $this->search_multiple = true;

        return $info;

    }


    /**
     * @param $namecache
     * @param $type
     * @param Builder $query
     * @param $withcache
     * @return bool
     */
    protected function getData($namecache, $type, $query, $withcache)
    {
        if (!$withcache || !$data = $this->getCache($namecache)) {
            if ($type == "countrelation") {
                $data = $query->get();
            } else {
                $data = $query->$type();
            }

            $this->setCache($namecache, $data);
        }

        return $data;
    }

    /**
     * @param $type
     * @param bool $withcache
     * @return array|bool|mixed
     */
    protected function prepareQuery($type, $withcache = true)
    {
        $info = $this->getInfoQuery();

        /**
         * @var Builder $query
         */
        $query = $info['query'];
        $sql = $query->toSql() . implode('-', $query->getBindings());
        $namecache = $this->getNameCache($info['relation']['repository'], $type, $sql);

        $result = false;
        if ($info['lastid']) {
            if ($info['relation']) {

                /** @var RepositoryAbstract $relation_repository */
                $relation_repository = $info['relation']['repository'];

                if (isset($this->entities[$info['lastid']][strtolower($this->getShortName($relation_repository))][$namecache])) {
                    $result = $this->entities[$info['lastid']][strtolower($this->getShortName($relation_repository))][$namecache];
                } else {
                    $typeget = ($type == 'count') ? 'countrelation' : 'get';
                    if ($type == 'count') {
                        if ($info['relation']['type'] == 'onetomany') {
                            $query->groupBy($info['relation']['field']);
                            $query->select([$relation_repository::$table_name . '.' . $info['relation']['field'] . ' as prim_key_ent']);
                            $query->addSelect($query->raw('count(' . $relation_repository::$primary_key . ') as total'));
                        } else {
                            $query->select([$info['relation']['relatedtable'] . '.' . $info['relation']['field'] . ' as prim_key_ent']);
                            $query->addSelect($query->raw('count(' . $info['relation']['relatedfield'] . ') as total'));
                            $query->groupBy($info['relation']['field']);
                        }
                    }
                    $data = $this->getData($namecache, $typeget, $query, $withcache);
                    $this->convertArrayToEntity($data, $sql, [
                        'relation' => $info['relation'],
                        'type'     => $type
                    ]);

                    if ($info['relation']['type'] == 'one' && $type == 'get') {
                        $result = $this->entities[$info['lastid']][strtolower($this->getShortName($relation_repository))];
                    } else {
                        $result = $this->entities[$info['lastid']][strtolower($this->getShortName($relation_repository))][$namecache];
                    }
                }

            } else {
                // get
                // frist
                if (!empty($this->entities[$info['lastid']]['object'])) {
                    $result = $this->entities[$info['lastid']]['object'];
                } else {
                    if ($data = $this->getData($namecache, 'first', $query, $withcache)) {
                        $result = $this->getObject($data);
                    } else {
                        return false;
                    }
                }
                // count
            }
        } else {
            $data = $this->getData($namecache, $type, $query, $withcache);
            if ($type == 'get') {
                $result = $this->convertArrayToEntity($data, $sql, [
                    'relation' => $info['relation'],
                    'type'     => 'get'
                ]);
            } elseif ($type == 'first') {
                $result = $this->getObject($data);
            } elseif ($type == 'count') {
                $result = $data;
            }

        }

        return $result;
    }

    /**
     * @param bool $withcache
     * @return array|bool|mixed
     */
    public function getRaw($withcache = true)
    {
        return $this->prepareQuery('raw', $withcache);
    }

    /**
     * @param bool $withcache
     * @return array|bool|mixed
     */
    public function get($withcache = true)
    {
        return $this->prepareQuery('get', $withcache);
    }

    /**
     * @param bool $withcache
     * @return array|bool|mixed
     */
    public function first($withcache = true)
    {
        return $this->prepareQuery('first', $withcache);
    }

    /**
     * @param bool $withcache
     * @return array|bool|mixed
     */
    public function count($withcache = true)
    {
        return $this->prepareQuery('count', $withcache);
    }

    /**
     * @param array $data
     * @return mixed
     */
    protected function getObject(array $data = [])
    {
        /**
         * @var EntityAbstract $object
         */
        $object = new $this->entity($data);

        if (!empty($data[$this->key])) {
            $id = $data[$this->key];
            $this->entities_id[] = $id;

            $object->setId($id);

            if (empty($this->entities[$id]['object'])) {
                $this->entities[$id]['object'] = $object;
            }
        }

        return $object;
    }


    /**
     * @param $data
     * @param $sql
     * @param bool $info
     * @return array
     */
    protected function convertArrayToEntity($data, $sql, $info = false)
    {
        $relation = ($info) ? $info['relation'] : false;
        $type = ($info) ? $info['type'] : false;

        $models = [];

        if ($relation) {
            $ids_complete = [];
            $repodb = $relation['repository']::find();
            $repo_name = strtolower($this->getShortName($relation['repository']));
        }

        foreach ($data as $row) {

            if ($relation) {
                $ids_complete[] = $row['prim_key_ent'];
                $namecache = $this->getNameCache($relation['repository'], $type, $sql);

                if ($type == 'count') {
                    $result = $this->entities[$row['prim_key_ent']][$repo_name][$namecache] = $row['total'];
                } elseif (($relation['type'] == 'onetomany' || $relation['type'] == 'manytomany') && $type == 'get') {
                    $result = $this->entities[$row['prim_key_ent']][$repo_name][$namecache][] = $repodb->getObject($row);
                } elseif ($relation['type'] != 'one' && $type == 'first') {
                    if (!isset($this->entities[$row['prim_key_ent']][$repo_name][$namecache])) {
                        $result = $this->entities[$row['prim_key_ent']][$repo_name][$namecache] = $repodb->getObject($row);
                    }
                } else {
                    $result = $this->entities[$row['prim_key_ent']][$repo_name] = $repodb->getObject($row);
                }

            } else {
                $result = $this->getObject($row);
            }

            $models[] = $result;

        }

        if ($relation) {
            $diff = array_diff($this->entities_id, array_unique($ids_complete));
            foreach ($diff as $item) {
                $namecache = $this->getNameCache($relation['repository'], $type, $sql);
                $this->entities[$item][$repo_name][$namecache] = false;
            }
        }

        return $models;

    }

    /**
     * @param array $data
     * @param array $relations
     * @return bool|int
     */
    public function insert(array $data, array $relations = [])
    {
        if (empty($data['created_at'])) {
            $data['created_at'] = date('Y-m-d H:i:s');
        }
        if (empty($data['updated_at'])) {
            $data['updated_at'] = date('Y-m-d H:i:s');
        }

        if (!empty($this->relations)) {
            foreach ($this->relations as $name => $info) {

                // add relation one on insert //
                if ($info['type'] == 'one') {

                    if (isset($data[$info['field']]) && is_numeric($data[$info['field']])) {

                    } elseif (!empty($info['required']) && $info['required'] && empty($relations[$name])) {
                        echo "La relacion $name es obligatoria en la entidad " . get_called_class();
                        return false;
                    } elseif (!empty($info['required']) && $info['required']) {
                        // la relacion es objecto pero no existe //
                        $id_relation = $relations[$name];
                        if (is_object($relations[$name]) && is_null($relations[$name]->getId())) {
                            $id_relation = $relations[$name]->save()->getId();
                        } elseif (is_object($relations[$name])) {
                            $id_relation = $relations[$name]->getId();
                        }
                        $data[$info['field']] = $id_relation;
                    }
                }

            }
        }
        ///////////////////////////////

        $this->restoreCache();
        $id = $this->query()->insertGetId($data);


        if (!empty($this->relations)) {
            foreach ($this->relations as $name => $info) {
                if ($info['type'] == 'onetomany' && !empty($relations[$name])) {
                    if (!is_array($relations[$name])) {
                        $relations[$name] = array($relations[$name]);
                    }

                    foreach ($relations[$name] as $relation) {
                        if (is_object($relation) && is_null($relation->getId())) {
                            $relation->setValue($info['field'], $id);
                            $relation->save()->getId();
                        } elseif (is_object($relation)) {
                            $relation->setValue($info['field'], $id);
                            $relation->update();
                        } else {
                            $r = $info['repository'];
                            $item = $r::findById($relation);
                            $item->setValue($info['field'], $id);
                            $item->update();
                        }
                    }
                }

                if ($info['type'] == 'manytomany' && !empty($relations[$name])) {
                    if (!is_array($relations[$name])) {
                        $relations[$name] = array($relations[$name]);
                    }

                    $inserts = [];
                    foreach ($relations[$name] as $relation) {
                        $id_relation = $relation;
                        if (is_object($relation) && is_null($relation->getId())) {
                            $id_relation = $relation->save()->getId();
                        } elseif (is_object($relation)) {
                            $id_relation = $relation->getId();
                        }
                        $inserts[] = [
                            $info['field']        => $id,
                            $info['relatedfield'] => $id_relation
                        ];
                    }

                    // insert many to many relation //
                    if (!empty($inserts)) {
                        $this->getConnection()->table($info['relatedtable'])->insert($inserts);
                        $this->restoreCache($info['repository']);
                    }

                }

            }
        }

        return $id;
    }

    public function update($id, array $data, array $relations = [])
    {
        if (empty($data['updated_at'])) {
            $data['updated_at'] = date('Y-m-d H:i:s');
        }

        //////////////////////////
        // update relations one //
        //////////////////////////
        if (!empty($this->relations)) {
            foreach ($this->relations as $name => $info) {

                // update relation one on update //
                if ($info['type'] == 'one' && !empty($relations[$name])) {
                    if (!isset($data[$info['field']])) {
                        // la relacion es objecto pero no existe //
                        $id_relation = $relations[$name];
                        if (is_object($relations[$name]) && is_null($relations[$name]->getId())) {
                            $id_relation = $relations[$name]->save()->getId();
                        } elseif (is_object($relations[$name])) {
                            $id_relation = $relations[$name]->getId();
                        }
                        $data[$info['field']] = $id_relation;
                    }
                }

                // update relation onetomany on update //
                if ($info['type'] == 'onetomany' && !empty($relations[$name])) {
                    if (!is_array($relations[$name])) {
                        $relations[$name] = array($relations[$name]);
                    }

                    $lastids = [];
                    foreach ($relations[$name] as $relation) {
                        if (is_object($relation) && is_null($relation->getId())) {
                            $relation->setValue($info['field'], $id);
                            $lastids[] = $relation->save()->getId();
                        } elseif (is_object($relation)) {
                            $relation->setValue($info['field'], $id);
                            $lastids[] = $relation->update()->getId();
                        } else {
                            $r = $info['repository'];
                            $item = $r::findById($relation);
                            $item->setValue($info['field'], $id);
                            $lastids[] = $item->update()->getId();
                        }
                    }
                    if (!empty($lastids)) {
                        $me = self::findById($id);
                        if ($rs = $me->find($name)->whereNotIn('id', $lastids)->get()) {
                            foreach ($rs as $r) {
                                $r->delete();
                            }
                        }
                    }

                }
                if ($info['type'] == 'onetomany' && !empty($relations['add'][$name])) {
                    if (!is_array($relations['add'][$name])) {
                        $relations['add'][$name] = array($relations['add'][$name]);
                    }

                    foreach ($relations['add'][$name] as $relation) {
                        if (is_object($relation) && is_null($relation->getId())) {
                            $relation->setValue($info['field'], $id);
                            $relation->save()->getId();
                        } elseif (is_object($relation)) {
                            $relation->setValue($info['field'], $id);
                            $relation->update();
                        } else {
                            $r = $info['repository'];
                            $item = $r::findById($relation);
                            $item->setValue($info['field'], $id);
                            $item->update();
                        }
                    }
                }
                if ($info['type'] == 'onetomany' && !empty($relations['del'][$name])) {
                    if (!is_array($relations['del'][$name])) {
                        $relations['del'][$name] = array($relations['del'][$name]);
                    }

                    $lastids = [];
                    foreach ($relations['del'][$name] as $relation) {
                        if (is_object($relation) && !is_null($relation->getId())) {
                            $lastids[] = $relation->getId();
                        } else {
                            $lastids[] = $relation;
                        }
                    }

                    if (!empty($lastids)) {
                        $me = self::findById($id);
                        if ($rs = $me->find($name)->whereIn('id', $lastids)->get()) {
                            foreach ($rs as $r) {
                                $r->delete();
                            }
                        }
                    }
                }
                /////////////////////

                // update relation onetomany on update //
                if ($info['type'] == 'manytomany' && !empty($relations[$name])) {
                    if (!is_array($relations[$name])) {
                        $relations[$name] = array($relations[$name]);
                    }

                    $inserts = [];
                    $lastids = [];
                    foreach ($relations[$name] as $relation) {
                        $id_relation = $relation;
                        if (is_object($relation) && is_null($relation->getId())) {
                            $id_relation = $relation->save()->getId();
                        } elseif (is_object($relation)) {
                            $id_relation = $relation->getId();
                        }
                        $inserts[] = [
                            $info['field']        => $id,
                            $info['relatedfield'] => $id_relation
                        ];
                        $lastids[] = $id_relation;
                    }

                    // insert many to many relation //
                    $restore = false;
                    if (!empty($inserts)) {
                        $this->getConnection()->table($info['relatedtable'])->insertIgnore($inserts);
                        $restore = true;
                    }

                    if (!empty($lastids)) {
                        $this->getConnection()->table($info['relatedtable'])->where($info['field'], '=',
                            $id)->whereNotIn($info['relatedfield'], $lastids)->delete();
                        $restore = true;
                    }

                    if ($restore) {
                        $this->restoreCache($info['repository']);
                    }

                }
                if ($info['type'] == 'manytomany' && !empty($relations['add'][$name])) {
                    if (!is_array($relations['add'][$name])) {
                        $relations['add'][$name] = array($relations['add'][$name]);
                    }

                    $inserts = [];
                    foreach ($relations['add'][$name] as $relation) {
                        $id_relation = $relation;
                        if (is_object($relation) && is_null($relation->getId())) {
                            $id_relation = $relation->save()->getId();
                        } elseif (is_object($relation)) {
                            $id_relation = $relation->getId();
                        }
                        $inserts[] = [
                            $info['field']        => $id,
                            $info['relatedfield'] => $id_relation
                        ];
                        $lastids[] = $id_relation;
                    }

                    // insert many to many relation //
                    if (!empty($inserts)) {
                        $this->getConnection()->table($info['relatedtable'])->insertIgnore($inserts);
                        $this->restoreCache($info['repository']);
                    }

                }
                if ($info['type'] == 'manytomany' && !empty($relations['del'][$name])) {
                    if (!is_array($relations['del'][$name])) {
                        $relations['del'][$name] = array($relations['del'][$name]);
                    }

                    $lastids = [];
                    foreach ($relations['del'][$name] as $relation) {
                        if (is_object($relation) && !is_null($relation->getId())) {
                            $lastids[] = $relation->getId();
                        } else {
                            $lastids[] = $relation;
                        }
                    }

                    if (!empty($lastids)) {
                        $this->getConnection()->table($info['relatedtable'])->where($info['field'], '=',
                            $id)->whereIn($info['relatedfield'], $lastids)->delete();
                        $this->restoreCache($info['repository']);
                    }
                }

            }
        }
        $this->restoreCache();
        return $this->query()->where($this->key, $id)->update($data);
    }

    public function delete($id)
    {
        if (!empty($this->relations)) {
            // borramos relaciones onetomany que estan definidas como delete cascade //
            foreach ($this->relations as $name => $info) {
                if ($info['type'] == 'onetomany') {
                    /**
                     * @var RepositoryAbstract
                     */
                    $info_repo = $info['repository'];
                    $table = $info_repo::$table_name;
                    if (!empty($info['delete']) && $info['delete'] == 'cascade') {
                        $this->getConnection()->table($table)
                            ->where($info['field'], '=', $id)
                            ->delete();
                    } else {
                        $this->getConnection()->table($table)
                            ->where($info['field'], '=', $id)
                            ->update(array(
                                $info['field'] => null
                            ));
                    }
                }

                if ($info['type'] == 'manytomany') {
                    $this->getConnection()->table($info['relatedtable'])
                        ->where($info['field'], '=', $id)
                        ->delete();
                }

                $this->restoreCache($info['repository']);
            }
            //////////////////////////////////////////////////////////////////////////
        }
        $this->restoreCache();
        return $this->query()->where($this->key, $id)->delete();
    }

    /**
     * @param $id
     * @param $relation_name
     * @return $this|array|bool|mixed
     */
    public function findrelation($id, $relation_name)
    {

        $this->last_id = $id;

        $repo = $this->relations[$relation_name]['repository'];
        $field = $this->relations[$relation_name]['field'];
        $type = $this->relations[$relation_name]['type'];
        $table = $repo::$table_name;
        $this->repo = $this->relations[$relation_name];

        if ($type == 'manytomany') {

            $related_table = $this->relations[$relation_name]['relatedtable'];
            $related_field = $this->relations[$relation_name]['relatedfield'];

            $this->querybuild = $this->getConnection()->table($related_table)
                ->select([$related_table . '.' . $field . ' as prim_key_ent', $table . '.*']);

            $this->querybuild->join($table, $table . '.id', '=', $related_table . '.' . $related_field);

            if (empty($this->entities_id)) {
                $this->querybuild = $this->querybuild
                    ->where($field, '=', $id);
            } else {
                $this->entities_id = array_unique($this->entities_id);
                $this->querybuild = $this->querybuild
                    ->whereIn($field, $this->entities_id);
            }
        }

        if ($type == 'onetomany') {

            $this->querybuild = $this->getConnection()->table($table)
                ->select([$table . '.' . $field . ' as prim_key_ent', $table . '.*']);

            if (empty($this->entities_id)) {
                $this->querybuild = $this->querybuild
                    ->where($field, '=', $id);
            } else {
                $this->entities_id = array_unique($this->entities_id);
                $this->querybuild = $this->querybuild
                    ->whereIn($field, $this->entities_id);
            }
        }

        if ($type == 'one') {

            $this->querybuild = $this->query()
                ->join($table, $table . '.id', '=', $this->table . '.' . $field)
                ->select([$this->table . '.' . $this->key . ' as prim_key_ent', $table . '.*']);

            if (empty($this->entities_id)) {
                $this->querybuild = $this->querybuild
                    ->where($this->table . '.' . $this->key, '=', $id);
                return $this->first();
            } else {
                $this->entities_id = array_unique($this->entities_id);
                $this->querybuild = $this->querybuild
                    ->whereIn($this->table . '.' . $this->key, $this->entities_id);
                return $this->get();
            }

        }

        return $this;
    }

    /**
     * @return self
     */
    public static function getInstance()
    {
        $class = get_called_class();
        if (!isset(self::$_instances[$class])) {
            self::$_instances[$class] = new $class();
        }
        return self::$_instances[$class];
    }

    public static function build(array $data = [])
    {
        return self::find()->getObject($data);
    }

    public static function findById($id)
    {
        $repo = self::find();

        $repo->last_id = $id;
        $repo->querybuild = $repo->query()->where($repo->key, '=', $id);
        return ($item = $repo->first()) ? $item : false;

    }

    public static function deleteById($id)
    {
        return self::find()->delete($id);
    }

    public static function all()
    {
        return self::find()->get();
    }

    public static function find()
    {
        return self::getInstance();
    }

    public static function getCount()
    {
        return self::find()->count();
    }

    public function __call($name, $arguments)
    {
        $scopes = $this->scopes();
        $this->querybuild = (!empty($this->querybuild)) ? $this->querybuild : $this->query();
        if (!empty($scopes[$name])) {
            $this->querybuild = call_user_func($scopes[$name], $this->querybuild, ...$arguments);
        } else {
            if (in_array($name, $this->no_multiple_query)) {
                $this->search_multiple = false;
            }
            if ($this->repo && $this->repo['type'] == 'manytomany' && $name == 'where') {
                $rp = $this->repo['repository'];
                $table = $rp::$table_name;
                $this->querybuild = $this->querybuild->where($table . '.' . $arguments[0], $arguments[1],
                    $arguments[2]);
            } else {
                $this->querybuild = call_user_func(array($this->querybuild, $name), ...$arguments);
            }
        }
        return $this;
    }


}