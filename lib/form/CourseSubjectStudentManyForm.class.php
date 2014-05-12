<?php
/*
 * Kimkëlen - School Management Software
 * Copyright (C) 2013 CeSPI - UNLP <desarrollo@cespi.unlp.edu.ar>
 *
 * This file is part of Kimkëlen.
 *
 * Kimkëlen is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License v2.0 as published by
 * the Free Software Foundation.
 *
 * Kimkëlen is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Kimkëlen.  If not, see <http://www.gnu.org/licenses/gpl-2.0.html>.
 */ ?>
<?php

/**
 * CourseSubjectStudent form.
 *
 * @package    sistema de alumnos
 * @subpackage form
 * @author     Your name here
 */
class CourseSubjectStudentManyForm extends sfFormPropel
{
  static $_students=array();

  public static function setAvailableStudentsForCourse(CourseSubject $course_subject)
  {
    self::$_students = array_merge($course_subject->getStudents(), SchoolBehaviourFactory::getInstance()->getAvailableStudentsForDivisionCourseSubject($course_subject, new Criteria(), true));
  }

  public static function getCriteriaForAvailableStudentsForCourseIds()
  {
    $ret = array();
    foreach (self::$_students as $st)
    {
      $ret[] = $st->getId();
    }

    $criteria = new Criteria();
    $criteria->add(StudentPeer::ID,$ret,Criteria::IN);

    return $criteria;
  }

  public function configure()
  {
    //Set the formatter to the form
    $sf_formatter_revisited = new sfWidgetFormSchemaFormatterRevisited($this);
    $this->getWidgetSchema()->addFormFormatter('Revisited', $sf_formatter_revisited);
    $this->getWidgetSchema()->setFormFormatterName('Revisited');

    $this->setWidget('id', new sfWidgetFormInputHidden());
    $this->setValidator('id', new sfValidatorPass());

    CourseSubjectStudentManyForm::setAvailableStudentsForCourse($this->getObject());

    $available_criteria = self::getCriteriaForAvailableStudentsForCourseIds();

    $this->setWidget('course_subject_student_list', new csWidgetFormStudentMany(array('criteria'=> $available_criteria)));

    $this->setValidator('course_subject_student_list', new sfValidatorPass());

    $this->mergePostValidator(new sfValidatorCallback(array(
          'callback' => array($this, 'postValidateAvailableStudents')
        )));

    $this->widgetSchema->setNameFormat('course_subject[%s]');
  }

  public function getModelName()
  {
    return 'CourseSubject';
  }

  public function updateDefaultsFromObject()
  {
    parent::updateDefaultsFromObject();

    if (isset($this->widgetSchema['course_subject_student_list']))
    {
      $values = array();
      foreach ($this->object->getCourseSubjectStudents() as $course_subject_student)
      {
        $values[] = $course_subject_student->getStudentId();
      }
      $this->setDefault('course_subject_student_list', $values);
    }
  }

  protected function doSave($con = null)
  {
    //parent::doSave($con);
    $this->saveCourseSubjectStudentList($con);
  }

  public function saveCourseSubjectStudentList($con = null)
  {
    if (!$this->isValid())
    {
      throw $this->getErrorSchema();
    }

    if (!isset($this->widgetSchema['course_subject_student_list']))
    {
      // somebody has unset this widget
      return;
    }

    if (is_null($con))
    {
      $con = $this->getConnection();
    }

    $this->values = $this->getValue('course_subject_student_list');

    foreach ($this->getObject()->getCourseSubjectStudents() as $course_subject_student)
    {
      //if student has marks or attendances/absences, it can't be deleted from course
      if (!in_array($course_subject_student->getStudentId(), $this->values))
      {
        if ($this->canDeleteCourseSubjectStudent($course_subject_student->getStudentId()) && $course_subject_student->countValidCourseSubjectStudentMarks() == 0 && $this->countAttendances($course_subject_student) == 0)
        {
          $course_subject_student->delete($con);
        }
        else
        {
          throw new Exception('Los alumnos seleccionados poseen datos que les impiden ser borrados de este curso.');
        }
      }
    }

    $already_ids = array_map(create_function('$c', 'return $c->getStudentId();'), $this->getObject()->getCourseSubjectStudents());
    $this->filterValues($already_ids);

    if (is_array($this->values))
    {
      $con->beginTransaction();
      try
      {
        foreach ($this->values as $value)
        {
          $course_subject_student = new CourseSubjectStudent();
          $course_subject_student->setCourseSubject($this->getObject());
          $course_subject_student->setStudentId($value);
          $course_subject_student->save($con);
        }
        $con->commit();
      }
      catch (Exception $e)
      {
        $con->rollBack();
        throw $e;
      }
    }
  }

  /*
   * Este metodo existe para no borrar un alumno si no se quito de la lista.
   * Por que si se hace como siempre en los double list, se borran las notas al agregar un nuevo alumno, cosa que no deberia
   */
  public function canDeleteCourseSubjectStudent($id)
  {
    foreach ($this->values as $k => $v)
    {
      $id .= '';
      if ($id == $v)
      {
        return false;
      }
    }
    return true;
  }

  /**
   * Este metodo lo que hace es recorrer los valores que devolvio el double list,
   * para que este agrege solo valores de elementos que no existan ya.
   * @param type $already_ids
   */
  public function filterValues($already_ids)
  {
    foreach($this->values as $k => $value)
    {
      if (in_array($value,$already_ids))
              unset($this->values[$k]);
    }
  }

  /*
   * Validates if chosen students are not already inscripted in a course for this subject
   */
  public function postValidateAvailableStudents(sfValidatorBase $validator, $values)
  {
    $duplicated_students = array();
    $student_ids = $values['course_subject_student_list'];
    $course_subject_id = $values['id'];

    foreach ($student_ids as $student_id)
    {
      if (CourseSubjectStudentPeer::countStudentInscriptionsForCareerSubjectSchoolYear($course_subject_id, $student_id) != 0)
      {
        $duplicated_students[] = StudentPeer::retrieveByPk($student_id);
      }
    }

    if ($duplicated_students)
    {
      $error = new sfValidatorError($validator, 'Los siguientes estudiantes seleccionados ya se encuentran inscriptos en otro curso para esta misma materia: ' . implode(',', $duplicated_students));
        throw new sfValidatorErrorSchema($validator, array('course_subject_student_list' => $error));
    }

    return $values;
  }

  private function countAttendances($course_subject_student)
  {
    return StudentAttendancePeer::doCountByCourseSubjectAndStudent($course_subject_student->getCourseSubject(), $course_subject_student->getStudent());
  }
}