<?php
class SearchableBehavior extends ModelBehavior {
	var $settings = array();
	var $model = null;
	
	var $_index = array();
	var $foreignKey = 0;
	var $_defaults = array(
		'rebuildOnUpdate' => true
	);
	
	var $SearchIndex = null;

	function setup(&$model, $settings = array()) {
		$settings = array_merge($this->_defaults, $settings);	
		$this->settings[$model->name] = $settings;
		$this->index[$model->name] = false;
	}
	
	function _indexData(&$model) {
		if (method_exists($model, 'indexData')) {
			return $model->indexData();
		} else {
			return $this->_index($model);
		}
	}
	
	function beforeSave(&$model) {
		if ($model->id) {
			$this->foreignKey = $model->id;		
		} else {
			$this->foreignKey = 0;
		}
		if ($this->foreignKey == 0 || $this->settings[$model->name]['rebuildOnUpdate']) {
			$this->_index[$model->name] = $this->_indexData($model);
		}
		return true;
	}
	
	function afterSave(&$model) {
		if ($this->_index[$model->name] !== false) {
			if (!$this->SearchIndex) {
				$this->SearchIndex = ClassRegistry::init('SearchIndex');
			}
			if ($this->foreignKey == 0) {
				$this->foreignKey = $model->getLastInsertID();
				$this->SearchIndex->create();
				$this->SearchIndex->save(
					array(
						'SearchIndex' => array(
							'model' => $model->name,
							'association_key' => $this->foreignKey,
							'data' => $this->_index[$model->name]
						)
					)
				);
			} else {
				$searchEntry = $this->SearchIndex->find('first',array('fields'=>array('id'),'conditions'=>array('model'=>$model->name,'association_key'=>$this->foreignKey)));
				$this->SearchIndex->save(
					array(
						'SearchIndex' => array(
							'id' => empty($searchEntry) ? 0 : $searchEntry['SearchIndex']['id'],
							'model' => $model->name,
							'association_key' => $this->foreignKey,
							'data' => $this->_index[$model->name]
						)
					)
				);				
			}
			$this->_index[$model->name] = false;
			$this->foreignKey = false;
		}
		return true;
	}
	
	function _index(&$model) {
		$index = array();
		$data = $model->data[$model->name];
		foreach ($data as $key => $value) {
			if (is_string($value)) {
				$columns = $model->getColumnTypes();
				if ($key != $model->primaryKey && isset($columns[$key]) && in_array($columns[$key],array('text','varchar','char','string'))) {
					$index []= strip_tags(html_entity_decode($value,ENT_COMPAT,'UTF-8'));
				}
			}
		}
		$index = join('. ',$index);
		$index = iconv('UTF-8', 'ASCII//TRANSLIT', $index);
		$index = preg_replace('/[\ ]+/',' ',$index);
		return $index;
	}

	function afterDelete(&$model) {
		if (!$this->SearchIndex) {
			$this->SearchIndex = ClassRegistry::init('SearchIndex');
		}
		$conditions = array('model'=>$model->alias, 'association_key'=>$model->id);
		$this->SearchIndex->deleteAll($conditions);
	}
	
	
	function search(&$model, $q, $findOptions = array()) {
		if (!$this->SearchIndex) {
			$this->SearchIndex = ClassRegistry::init('SearchIndex');
		}
		$this->SearchIndex->searchModels($model->name);
		if (!isset($findOptions['conditions'])) {
			$findOptions['conditions'] = array();
		}
		App::import('Core', 'Sanitize');
		$q = Sanitize::escape($q);
		$findOptions['conditions'] = array_merge(
			$findOptions['conditions'], array("MATCH(SearchIndex.data) AGAINST('$q' IN BOOLEAN MODE)")
		);
		return $this->SearchIndex->find('all', $findOptions);		
	}
	
}
?>
