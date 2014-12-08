<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Users_model extends CI_Model {

  private $_units_table = "users_units";

  private $_research_table = "users_research";

  private $_researchers_table = "users_researchers";

  public function __construct()
  {
    parent::__construct();
    $this->load->database();
  }

  // RESEARCH STUFF

  public function get_research_list(
                    $user_id, $field_ids = NULL, $field_level_ids = NULL
                  )
  {
    $this->db->select(
      array("id", "field_id", "field_level_id", "experience", "rounds", "time")
    );
    $this->db->where("user_id", $user_id);
    if (count($field_ids) > 0)
      $this->db->where(
        sprintf("field_id IN ('%s')", implode("','", $field_ids))
      );
    if (count($field_level_ids) > 0)
      $this->db->where(
        sprintf("field_level_id IN ('%s')", implode("','", $field_level_ids))
      );
    $query = $this->db->get($this->_research_table);
    $research_list = array();
    foreach ($query->result() as $row)
    {
      $research_list[$row->id] = array(
        "id" => $row->id,
        "field_id" => $row->field_id,
        "field_level_id" => $row->field_level_id,
        "experience" => $row->experience,
        "rounds" => $row->rounds,
        "time" => $row->time
      );
    }
    return $research_list;
  }

  public function get_researchers_amount($user_id)
  {
    $this->load->model("units_model");
    $researcher_id = $this->units_model->get_unit_id_by_name("researcher");

    $user_unit_ids = array();
    $this->db->select("id");
    $this->db->where("user_id", $user_id);
    $this->db->where("unit_id", $researcher_id);
    $this->db->where("rounds", 0);
    $this->db->from($this->_units_table);
    $query = $this->db->get();
    foreach ($query->result() as $row) {
      $user_unit_ids[] = $row->id;
    }
    $all_amount = count($user_unit_ids);

    $this->db->where("user_id", $user_id);
    $this->db->where_in("unit_id", $user_unit_ids);
    $this->db->from($this->_researchers_table);
    $active_amount = $this->db->count_all_results();

    return array(
      "active" => $active_amount,
      "inactive" => $all_amount - $active_amount
    );
  }

  public function get_free_researchers($user_id, $researchers_needed)
  {
    $this->load->model("units_model");
    $researcher_id = $this->units_model->get_unit_id_by_name("researcher");
    $sql = sprintf(
      "SELECT m.id, m.unit_id, m.level_id FROM %s AS m ".
      "LEFT JOIN %s AS n ON (n.unit_id = m.id AND n.user_id = m.user_id) ".
      "WHERE m.user_id = ? AND m.unit_id = ? AND m.rounds = ? ".
      "AND n.research_id IS NULL ".
      "ORDER BY m.level_id DESC ".
      "LIMIT %s",
      $this->_units_table, $this->_researchers_table,
      $researchers_needed
    );
    $query = $this->db->query($sql, array($user_id, $researcher_id, 0));
    $researchers = array();
    foreach ($query->result() as $row)
    {
      $researchers[$row->id] = array(
        "id" => $row->id, // use as unit_id in users_researchers
        "volume" =>  $this->units_model->get_unit_volume(
          $row->unit_id, $row->level_id
        )
      );
    }
    return $researchers;
  }

  public function add_researchers($user_id, $research_id, $researchers)
  {
    $result = TRUE;
    foreach ($researchers as $id => $researcher)
    {
      $result = $result && $this->db->insert(
        $this->_researchers_table,
        array(
          "unit_id" => $id,
          "user_id" => $user_id,
          "research_id" => $research_id
        )
      );
    }
    return $result;
  }

  public function remove_researchers($user_id, $research_id) {
    return $this->db->delete(
      $this->_researchers_table,
      array("user_id" => $user_id, "research_id" => $research_id)
    );
  }

  public function update_research(
                    $user_id, $field_id, $field_level_id,
                    $researchers_needed, $experience_needed
                  ) {
      // re/start or stop research
      $research_list = $this->get_research_list(
        $user_id, array($field_id), array($field_level_id)
      );
      if ($researchers_needed > 0)
      {
        // get list of ids with volumes of free researchers
        $researchers = $this->get_free_researchers($user_id, $researchers_needed);
        // get exp volume per round by researcher volumes
        $researchers_exp_volume = 0;
        foreach ($researchers as $researcher)
          $researchers_exp_volume += $researcher["volume"];

        if (count($research_list) == 1)
        {
          $current_research = current($research_list);
          $this->add_researchers($user_id, $current_research["id"], $researchers);

          $data = array(
            "rounds" => ceil($current_research["experience"] / $researchers_exp_volume),
            "time" => time()
          );
          $this->db->where("id", $current_research["id"]);
          return $this->db->update($this->_research_table, $data);
        }
        else
        {
          $data = array(
            "user_id" => $user_id,
            "field_id" => $field_id,
            "field_level_id" => $field_level_id,
            "experience" => $experience_needed,
            "rounds" => ceil($experience_needed / $researchers_exp_volume),
            "time" => time()
          );
          $result = $this->db->insert($this->_research_table, $data);
          if ($result == TRUE && $experience_needed > 0)
            $this->add_researchers($user_id, $this->db->insert_id(), $researchers);
          return $result;
        }
      }
      else if (count($research_list) == 1)
      {
        // pause research
        $current_research = current($research_list);
        $this->remove_researchers($user_id, $current_research["id"]);

        $data = array(
          "time" => 0
        );
        $this->db->where("id", $current_research["id"]);
        return $this->db->update($this->_research_table, $data);
      }
    }
}