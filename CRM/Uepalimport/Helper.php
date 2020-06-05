<?php

class CRM_Uepalimport_Helper {
  public static function onEnd(CRM_Queue_TaskContext $ctx) {
    CRM_Core_Session::setStatus('Done.', 'Queue', 'success');
  }

  public static function process_tmp_paroisses_task(CRM_Queue_TaskContext $ctx, $id) {
    $sql = "
      SELECT
        *
      FROM
        tmp_paroisses
      WHERE
        id = $id
    ";
    $dao = CRM_Core_DAO::executeQuery($sql);
    if ($dao->fetch()) {
      // check if the contact exists
      $params = [
        'organization_name' => $dao->organization_name,
        'contact_type' => 'Organization',
        'contact_sub_type' => ['paroisse'],
      ];
      $result = civicrm_api3('Contact', 'get', $params);
      if ($result['count'] == 0) {
        // create the contact
        $params['external_identifier'] = $dao->external_identifier;

        // add address
        if ($dao->street_address) {
          $params['api.address.create'] = ['street_address' => $dao->street_address];
          $params['api.address.create']['location_type_id'] = 2; // work
          if ($dao->postal_code) {
            $params['api.address.create']['postal_code'] = $dao->postal_code;
            $params['api.address.create']['state_province_id'] = self::getFrenchDepartment($dao->postal_code);
          }
          if ($dao->city) {
            $params['api.address.create']['city'] = $dao->city;
          }
          if ($dao->supplemental_address_1) {
            $params['api.address.create']['supplemental_address_1'] = $dao->supplemental_address_1;
          }
          $params['api.address.create']['country_id'] = 1076;
        }

        // add phone
        if ($dao->phone) {
          $params['api.phone.create'] = [
            'phone' => $dao->phone,
            'location_type_id' => 2, // work
            'phone_type_id' => 1,
          ];
        }

        // add fax
        if ($dao->fax) {
          $suffix = '';
          if (array_key_exists('api.phone.create', $params)) {
            $suffix = '.2';
          }
          $params["api.phone.create$suffix"] = [
            'phone' => $dao->fax,
            'location_type_id' => 2, // work
            'phone_type_id' => 3, // fax
          ];
        }

        // add email
        if ($dao->email) {
          $params['api.email.create'] = [
            'email' => $dao->email,
            'location_type_id' => 2, // work
          ];
        }

        // add website
        if ($dao->website) {
          $params['api.website.create'] = [
            'url' => $dao->website,
            'location_type_id' => 1, // work
          ];
        }

        // add custom fields
        $config = new CRM_Uepalconfig_Config();
        if ($dao->custom_field_eglise) {
          $params['custom_' . $config->getCustomField_paroisseDetailEglise()['id']] = $dao->custom_field_eglise;
        }
        if ($dao->custom_field_nombre_paroissiens) {
          $params['custom_' . $config->getCustomField_paroisseDetailNombreParoissiens()['id']] = $dao->custom_field_nombre_paroissiens;
        }
        if ($dao->custom_field_nombre_electeurs) {
          $params['custom_' . $config->getCustomField_paroisseDetailNombreElecteurs()['id']] = $dao->custom_field_nombre_electeurs;
        }
        if ($dao->custom_field_theologie_reforme) {
          $params['custom_' . $config->getCustomField_paroisseDetailTheologie()['id']] = [$dao->custom_field_theologie_reforme . 'e'];
        }
        if ($dao->custom_field_theologie_lutherien) {
          $params['custom_' . $config->getCustomField_paroisseDetailTheologie()['id']] = [$dao->custom_field_theologie_lutherien . 'ne'];
        }

        // add custom fields for inspection/consistoire
        if ($dao->consistoire_external_identifier) {
          $inspCons = self::getContactByExternalId($dao->consistoire_external_identifier);
          if ($inspCons) {
            $params['custom_' . $config->getCustomField_paroisseDetailConsistoireLutherien()['id']] = $inspCons['id'];
          }
        }
        if ($dao->inspection_external_identifier) {
          if ($inspCons) {
            $params['custom_' . $config->getCustomField_paroisseDetailInspectionConsistoireReforme()['id']] = $inspCons['id'];
          }
        }

        $result = civicrm_api3('Contact', 'create', $params);
      }
    }

    return TRUE;
  }

  public static function process_tmp_individus_task(CRM_Queue_TaskContext $ctx, $id) {
    $sql = "
      SELECT
        *
      FROM
        tmp_individus
      WHERE
        id = '$id'
    ";
    $dao = CRM_Core_DAO::executeQuery($sql);
    if ($dao->fetch()) {
      // check if the contact exists
      if (self::getContactByExternalId($dao->id) === FALSE) {
        $params = [
          'contact_type' => 'Individual',
          'external_identifier' => $dao->id,
          'first_name' => $dao->first_name,
          'last_name' => $dao->last_name,
          'gender_id' => $dao->gender == 'Féminin' ? 1 : 2,
          'prefix_id' => $dao->prefix == 'Madame' ? 1 : 3,
          'job_title' => $dao->job_title,
          'source' => $dao->source,
        ];

        if ($dao->birth_year) {
          $params['birth_date'] = $dao->birth_year . '-01-01';
        }

        // create the contact
        $contact = civicrm_api3('Contact', 'create', $params);

        // add address
        self::createAddress($contact['id'], 1, $dao->street_address, $dao->supplemental_address_1, $dao->postal_code, $dao->city, 'France');

        // add phone
        if ($dao->home_phone) {
          self::createPhone($contact['id'], $dao->home_phone, 1, 1);
        }

        // add mobile phone
        if ($dao->home_mobile_phone) {
          self::createPhone($contact['id'], $dao->home_mobile_phone, 1, 2);
        }

        // add email
        if ($dao->home_email) {
          self::createEmail($contact['id'], $dao->home_email, 1);
        }

        $config = new CRM_Uepalconfig_Config();

        // add the CP relationship and the others
        if ($dao->cp_member_id) {
          $relTypeId = $config->getRelationshipType_estMembreEluDe()['id'];
          self::createRelationship($contact['id'], $dao->cp_member_id, $relTypeId, $dao->cp_member_start_date, $dao->cp_member_end_date);

          if ($dao->cp_member_old1_start_date && $dao->cp_member_old1_end_date) {
            self::createRelationship($contact['id'], $dao->cp_member_id, $relTypeId, $dao->cp_member_old1_start_date, $dao->cp_member_old1_end_date);
          }
          if ($dao->cp_member_old2_start_date && $dao->cp_member_old2_end_date) {
            self::createRelationship($contact['id'], $dao->cp_member_id, $relTypeId, $dao->cp_member_old2_start_date, $dao->cp_member_old2_end_date);
          }
          if ($dao->cp_member_old3_start_date && $dao->cp_member_old3_end_date) {
            self::createRelationship($contact['id'], $dao->cp_member_id, $relTypeId, $dao->cp_member_old3_start_date, $dao->cp_member_old3_end_date);
          }
        }


        if ($dao->cp_president_id) {
          $relTypeId = $config->getRelationshipType_estPresidentDe()['id'];
          self::createRelationship($contact['id'], $dao->cp_president_id, $relTypeId, '', '');
        }

        if ($dao->cp_vice_president_id) {
          $relTypeId = $config->getRelationshipType_estVicePresidentDe()['id'];
          self::createRelationship($contact['id'], $dao->cp_vice_president_id, $relTypeId, '', '');
        }

        if ($dao->cp_secratary_id) {
          $relTypeId = $config->getRelationshipType_estSecretaireDe()['id'];
          self::createRelationship($contact['id'], $dao->cp_secratary_id, $relTypeId, '', '');
        }

        if ($dao->cp_treasurer_id) {
          $relTypeId = $config->getRelationshipType_estTresorierDe()['id'];
          self::createRelationship($contact['id'], $dao->cp_treasurer_id, $relTypeId, '', '');
        }

        if ($dao->cp_invited_id) {
          $relTypeId = $config->getRelationshipType_estMembreInviteDe()['id'];
          self::createRelationship($contact['id'], $dao->cp_invited_id, $relTypeId, '', '');
        }
      }
    }

    return TRUE;
  }

  public static function process_tmp_pasteurs_actifs_task(CRM_Queue_TaskContext $ctx, $id) {
    $sql = "
      SELECT
        *
      FROM
        tmp_pasteurs_actifs
      WHERE
        id = '$id'
    ";
    $dao = CRM_Core_DAO::executeQuery($sql);
    if ($dao->fetch()) {
      // check if the contact exists
      if (self::getContactByExternalId($dao->id) === FALSE) {
        $params = [
          'contact_type' => 'Individual',
          'contact_sub_type' => ['ministre'],
          'external_identifier' => $dao->id,
          'first_name' => $dao->first_name,
          'last_name' => $dao->last_name,
          'gender_id' => $dao->gender == 'Féminin' ? 1 : 2,
          'prefix_id' => $dao->prefix == 'Mme' ? 1 : 3,
          'source' => $dao->source,
        ];

        if ($dao->nick_name) {
          $params['nick_name'] = $dao->first_name . ' ' . $dao->nick_name;
        }

        if ($dao->birth_year) {
          if ($dao->birth_day_month) {
            $parts = explode('/', $dao->birth_day_month);
            if (count($parts) >= 2) {
              // add month - day to the birth year
              $params['birth_date'] = $dao->birth_year . '-' . $parts[1] . '-' . $parts[0];
            }
            else {
              $params['birth_date'] = $dao->birth_year . '-01-01';
            }
          }
          else {
            $params['birth_date'] = $dao->birth_year . '-01-01';
          }
        }

        if ($dao->formal_title) {
          $params['formal_title'] = $dao->formal_title;
        }
        else {
          if ($dao->relationship_pasteur_de) {
            $params['formal_title'] = $dao->gender == 'Féminin' ? 'la Pasteure' : 'le Pasteur';
          }
          elseif ($dao->relationship_vicaire) {
            $params['formal_title'] = $dao->gender == 'le Vicaire';
          }
          elseif ($dao->relationship_suffragant) {
            $params['formal_title'] = $dao->gender == 'Féminin' ? 'la Suffragante' : 'le Suffragant';
          }
        }

        // add custom fields
        $config = new CRM_Uepalconfig_Config();
        if ($dao->custom_field_annee_consecration) {
          $params['custom_' . $config->getCustomField_ministreDetailAnneeConsecration()['id']] = $dao->custom_field_annee_consecration;
        }
        if ($dao->custom_field_annee_entree_ministere) {
          $params['custom_' . $config->getCustomField_ministreDetailAnneeEntreeMinistere()['id']] = $dao->custom_field_annee_entree_ministere;
        }
        if ($dao->custom_field_annee_entree_poste_actuel) {
          $params['custom_' . $config->getCustomField_ministreDetailAnneeEntreePosteActuel()['id']] = $dao->custom_field_annee_entree_poste_actuel;
        }
        if ($dao->custom_field_datecafp) {
          $params['custom_' . $config->getCustomField_ministreDetailDateCAFP()['id']] = self::convertExcelDate($dao->custom_field_datecafp);
        }
        if ($dao->custom_field_degree) {
          $params['custom_' . $config->getCustomField_ministreDetailDiplomes()['id']] = $dao->custom_field_degree;
        }

        // create the contact
        $contact = civicrm_api3('Contact', 'create', $params);

        // add home address
        if ($dao->home_street_address) {
          self::createAddress($contact['id'], 1, $dao->home_street_address, $dao->home_supplemental_address1, $dao->home_postal_code, $dao->home_city, $dao->home_country);
        }

        // add home phones
        if ($dao->home_phone) {
          self::createPhone($contact['id'], $dao->home_phone, 1, 1);
        }
        if ($dao->home_mobile_phone) {
          self::createPhone($contact['id'], $dao->home_mobile_phone, 1, 2);
        }
        if ($dao->home_fax) {
          self::createPhone($contact['id'], $dao->home_fax, 1, 3);
        }

        // add home email
        if ($dao->home_email) {
          self::createEmail($contact['id'], $dao->home_email, 1);
        }

        // add work address
        if ($dao->work_street_address) {
          self::createAddress($contact['id'], 2, $dao->work_street_address, $dao->work_supplemental_address1, $dao->work_postal_code, $dao->work_city, $dao->work_country);
        }

        // add work phones
        if ($dao->work_phone) {
          self::createPhone($contact['id'], $dao->work_phone, 2, 1);
        }
        if ($dao->work_mobile_phone) {
          self::createPhone($contact['id'], $dao->work_mobile_phone, 2, 2);
        }
        if ($dao->work_fax) {
          self::createPhone($contact['id'], $dao->work_fax, 2, 3);
        }

        // add work email
        if ($dao->work_email) {
          self::createEmail($contact['id'], $dao->work_email, 2);
        }

        // add other phones
        if ($dao->other_phone) {
          self::createPhone($contact['id'], $dao->other_phone, 4, 1);
        }
        if ($dao->other_mobile_phone) {
          self::createPhone($contact['id'], $dao->other_mobile_phone, 4, 2);
        }

        // add releationships
        if ($dao->relationship_pasteur_de) {
          $relTypeId = $config->getRelationshipType_estPasteurNommeDe()['id'];
          self::createRelationship($contact['id'], $dao->relationship_pasteur_de, $relTypeId, '', '');
        }
        if ($dao->relationship_membre_droit_cp) {
          $relTypeId = $config->getRelationshipType_estMembreDeDroitDe()['id'];
          self::createRelationship($contact['id'], $dao->relationship_membre_droit_cp, $relTypeId, '', '');
        }
        if ($dao->relationship_tresorier_cp) {
          $relTypeId = $config->getRelationshipType_estTresorierDe()['id'];
          self::createRelationship($contact['id'], $dao->relationship_tresorier_cp, $relTypeId, '', '');
        }
        if ($dao->relationship_invite_cp) {
          $relTypeId = $config->getRelationshipType_estMembreInviteDe()['id'];
          self::createRelationship($contact['id'], $dao->relationship_invite_cp, $relTypeId, '', '');
        }
        if ($dao->relationship_secretaire_cp) {
          $relTypeId = $config->getRelationshipType_estSecretaireDe()['id'];
          self::createRelationship($contact['id'], $dao->relationship_secretaire_cp, $relTypeId, '', '');
        }
        if ($dao->relationship_president) {
          $relTypeId = $config->getRelationshipType_estPresidentDe()['id'];
          self::createRelationship($contact['id'], $dao->relationship_president, $relTypeId, '', '');
        }
        if ($dao->relationship_vice_president) {
          $relTypeId = $config->getRelationshipType_estVicePresidentDe()['id'];
          self::createRelationship($contact['id'], $dao->relationship_vice_president, $relTypeId, '', '');
        }
        if ($dao->relationship_suffragant) {
          $relTypeId = $config->getRelationshipType_estSuffragantDe()['id'];
          self::createRelationship($contact['id'], $dao->relationship_suffragant, $relTypeId, '', '');
        }

        // add the tag
        if ($dao->relationship_pasteur_de) {
          self::createContactTag($contact['id'], 'Pasteur·e');
        }
        elseif ($dao->relationship_vicaire) {
          self::createContactTag($contact['id'], 'Vicaire');
        }
        elseif ($dao->relationship_suffragant) {
          self::createContactTag($contact['id'], 'Suffragant·e');
        }
      }
    }

    return TRUE;
  }

  public static function process_tmp_inspections_task(CRM_Queue_TaskContext $ctx, $id) {
    $sql = "
      SELECT
        *
      FROM
        tmp_inspections
      WHERE
        id = $id
    ";
    $dao = CRM_Core_DAO::executeQuery($sql);
    if ($dao->fetch()) {
      // check if the contact exists
      if (self::getContactByExternalId($dao->id) === FALSE) {
        $params = [
          'contact_type' => 'Organization',
          'contact_sub_type' => ['inspection_consistoire_reforme'],
          'organization_name' => $dao->organization_name,
          'external_identifier' => $dao->external_identifier,
        ];

        // create the contact
        $contact = civicrm_api3('Contact', 'create', $params);

        // add the address
        self::createAddress($contact['id'], 2, '-', '', $dao->state_province . '000', $dao->city, $dao->country);
      }
    }

    return TRUE;
  }

  public static function process_tmp_consistoires_task(CRM_Queue_TaskContext $ctx, $id) {
    $sql = "
      SELECT
        *
      FROM
        tmp_consistoires
      WHERE
        id = $id
    ";
    $dao = CRM_Core_DAO::executeQuery($sql);
    if ($dao->fetch()) {
      // check if the contact exists
      if (self::getContactByExternalId($dao->id) === FALSE) {
        $params = [
          'contact_type' => 'Organization',
          'contact_sub_type' => ['consistoire_lutherien'],
          'organization_name' => $dao->organization_name,
          'external_identifier' => $dao->external_identifier,
        ];

        // create the contact
        $contact = civicrm_api3('Contact', 'create', $params);

        // add the address
        self::createAddress($contact['id'], 2, '-', '', $dao->state_province . '000', $dao->city, $dao->country);

        // add the relationship with the inspection
        $config = new CRM_Uepalconfig_Config();
        $relTypeId = $config->getRelationshipType_estInspecteurDe()['id'];
        self::createRelationship($contact['id'], $dao->relationship_pasteur_de, $relTypeId, '', '');
      }
    }

    return TRUE;
  }

  public static function createAddress($contactId, $locationTypeId, $streetAddress, $supplementalAddress, $postalCode, $city, $country) {
    $params = [
      'contact_id' => $contactId,
      'location_type_id' => $locationTypeId,
      'street_address' => $streetAddress,
    ];

    if ($supplementalAddress) {
      $params['supplemental_address_1'] = $supplementalAddress;
    }

    $params['country_id'] = self::getCountryId(strtolower($country));

    if ($postalCode && $params['country_id'] == 1076) {
      $params['postal_code'] = $postalCode;
      $params['state_province_id'] = self::getFrenchDepartment($postalCode);
    }
    if ($city) {
      $params['city'] = $city;
    }

    civicrm_api3('Address', 'create', $params);
  }

  public static function createEmail($contactId, $email, $locationTypeId) {
    $params = [
      'contact_id' => $contactId,
      'email' => $email,
      'location_type_id' => $locationTypeId,
    ];
    civicrm_api3('Email', 'create', $params);
  }

  public static function createPhone($contactId, $phone, $locationTypeId, $phoneTypeId) {
    $params = [
      'contact_id' => $contactId,
      'phone' => $phone,
      'location_type_id' => $locationTypeId,
      'phone_type_id' => $phoneTypeId,
    ];
    civicrm_api3('Phone', 'create', $params);
  }

  public static function getCountryId($country) {
    $countryId = 1076;

    if ($country == 'allemagne') {
      $countryId = 1082;
    }
    elseif ($country == 'suisse') {
      $countryId = 1205;
    }
    elseif ($country == 'belgique') {
      $countryId = 1020;
    }

    return $countryId;
  }

  public static function createRelationship($contactIdA, $exaternalContactIdB, $relationshipTypeId, $startDate, $endDate) {
    // get the corresponding contact
    $targetContact = self::getContactByExternalId($exaternalContactIdB);
    if ($targetContact === FALSE) {
      watchdog('import', "cannot find contact with external id = " . $exaternalContactIdB);
    }
    else {
      $params = [
        'contact_id_a' => $contactIdA,
        'contact_id_b' => $targetContact['id'],
        'is_active' => 1,
        'relationship_type_id' => $relationshipTypeId,
      ];

      if ($startDate) {
        $convertedDate = strtotime($startDate);
        if ($convertedDate) {
          $params['start_date'] = date("Y-m-d", $convertedDate);
        }
      }

      if ($endDate) {
        $convertedDate = strtotime($endDate);
        if ($convertedDate) {
          $params['end_date'] = date("Y-m-d", $convertedDate);
          if ($convertedDate < time()) {
            $params['is_active'] = 0;
          }
        }
      }

      civicrm_api3('Relationship', 'create', $params);
    }
  }

  public function createContactTag($contactId, $tag) {
    $t = civicrm_api3('Tag', 'getsingle', ['name' => $tag]);
    $params = [
      'entity_table' => 'civicrm_contact',
      'entity_id' => $contactId,
      'tag_id' => $t['id'],

    ];
    civicrm_api3('EntityTag', 'create', $params);
  }

  public static function getContactByExternalId($id) {
    try {
      $contact = civicrm_api3('Contact', 'getsingle', ['external_identifier' => $id]);
      return $contact;
    }
    catch (Exception $e) {
      return FALSE;
    }
  }

  public static function getFrenchDepartment($postalCode) {
    $sql = "select id from civicrm_state_province where country_id = 1076 and abbreviation = %1";
    $sqlParams = [
      1 => [substr($postalCode, 0, 2), 'String'],
    ];
    return CRM_Core_DAO::singleValueQuery($sql, $sqlParams);
  }

  public static function convertExcelDate($daysSince1900) {
    $date = date_create('1900-01-01');
    date_add($date, date_interval_create_from_date_string("$daysSince1900 days"));
    return date_format($date,'Y-m-d');
  }


}
