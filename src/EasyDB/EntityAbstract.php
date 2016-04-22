<?php

namespace EasyDB;

abstract class EntityAbstract
{
    /**
     * @var AbstractRepository
     */
    protected $repository;
    /**
     * @var
     */
    protected $id;
    /**
     * @var array
     */
    protected $entityData = [];
    /**
     * @var array
     */
    protected $relationsData = [];
    /**
     * @var array
     */
    protected $valuesToUpdate = [];

    /**
     * EntityAbstract constructor.
     * @param array $data
     */
    public function __construct(array $data = [])
    {
        $this->entityData = $data;
    }

    /**
     * @param $id
     * @return $this
     */
    public function setId($id)
    {
        $this->id = $id;
        return $this;
    }

    /**
     * @return mixed
     */
    public function getId()
    {
        return $this->id;
    }

    /**
     * @param $key
     * @return mixed
     */
    public function getValue($key)
    {
        return (!empty($this->valuesToUpdate[$key])) ? $this->valuesToUpdate[$key] : $this->entityData[$key];
    }

    /**
     * @param $key
     * @param $value
     */
    public function setValue($key, $value)
    {
        $this->valuesToUpdate[$key] = $value;
    }

    /**
     * @return $this
     */
    public function save()
    {
        if ($this->beforeSave() === false) {
            return $this;
        }

        $repo = $this->repository;
        $this->entityData = $this->valuesToUpdate + $this->entityData;
        $this->id = $repo::find()->insert($this->entityData, $this->relationsData);
        $this->valuesToUpdate = [];

        $this->afterSave();

        return $this;
    }

    /**
     *
     */
    public function insert()
    {
        $this->save();
    }

    /**
     * @return $this
     */
    public function update()
    {
        $old_values = $this->entityData;

        if (!empty($this->valuesToUpdate) || !empty($this->relationsData)) {

            if ($this->beforeUpdate($this->valuesToUpdate) === false) {
                return $this;
            }

            $repo = $this->repository;
            $repo::find()->update($this->getId(), $this->valuesToUpdate, $this->relationsData);
            $this->entityData = $this->valuesToUpdate + $this->entityData;
            $this->valuesToUpdate = [];

            $this->afterUpdate($old_values);
        }


        return $this;
    }

    /**
     * @return $this
     */
    public function delete()
    {
        if ($this->beforeSave() === false) {
            return $this;
        }
        $repo = $this->repository;
        $repo::find()->delete($this->getId());
        $this->afterDelete();
        return $this;
    }

    /**
     * @param $relation_name
     * @return $this|array|bool|mixed
     */
    public function find($relation_name)
    {
        $repo = $this->repository;
        return $repo::find()->findrelation($this->getId(), $relation_name);
    }

    /**
     * @param $relation_name
     * @param $data
     * @return $this
     */
    public function set($relation_name, $data)
    {
        $this->relationsData[$relation_name] = $data;
        return $this;
    }

    /**
     * @param $relation_name
     * @param $data
     * @return $this
     */
    public function add($relation_name, $data)
    {
        $this->relationsData['add'][$relation_name] = $data;
        return $this;
    }

    /**
     * @param $relation_name
     * @param $data
     * @return $this
     */
    public function del($relation_name, $data)
    {
        $this->relationsData['del'][$relation_name] = $data;
        return $this;
    }

    /**
     *
     */
    public function beforeSave()
    {
    }

    /**
     *
     */
    public function afterSave()
    {
    }

    /**
     * @param array $update_values
     */
    public function beforeUpdate(array $update_values)
    {
    }

    /**
     * @param array $before_update_values
     */
    public function afterUpdate(array $before_update_values)
    {
    }

    /**
     *
     */
    public function beforeDelete()
    {
    }

    /**
     *
     */
    public function afterDelete()
    {
    }

    /**
     * @return array
     */
    public function __toString()
    {
        return $this->entityData;
    }

    /**
     * @param $name
     * @return mixed
     */
    public function __get($name)
    {
        return $this->getValue($name);
    }

    /**
     * @param $name
     * @param $value
     * @return $this
     */
    public function __set($name, $value)
    {
        $this->setValue($name, $value);
        return $this;
    }
}