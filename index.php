<?php
// Assuming you installed from Composer:
require "vendor/autoload.php";
require "simple_html_dom.php";
use PHPHtmlParser\Dom;
$collection = (new MongoDB\Client)->reg->Teacher;
$results = run(2560,1);

$results = preprocessCourses($results);
foreach($results as &$result){
    foreach($result['teacher'] as &$teacher){
        $tmpTeacher = $teacher;
        $teacher = json_decode(json_encode($collection->findOne(['officerName'=>$teacher['officerName'],'officerSurname'=>$teacher['officerSurname']])),true);
        if($teacher){
            $teacher['money'] = 0;
            unset($teacher['_id']);
            unset($teacher['updated_at']);
        }else{
            echo "case:teacher";
            var_dump($tmpTeacher);
        }
    }
    foreach($result['enrollSeats'] as &$enrollSeat){
        $enrollSeat['teacher'] = arrayMultiColumn($result['teacher'],['officerCode']);
    }
}
// print_r(json_encode($results));
$collection = (new MongoDB\Client)->workteach->TeachLectureLab;
$insertManyResult = $collection->insertMany($results);
printf("Inserted %d document(s)\n", $insertManyResult->getInsertedCount());
function run($year,$semester){
    $dom = new Dom;

    $url = 'http://reg.buu.ac.th/registrar/class_info_1.asp?coursestatus=O00&facultyid=003%A4%B3%D0%C7%D4%B7%C2%D2%C8%D2%CA%B5%C3%EC&maxrow=9999999&acadyear='.$year.'&semester='.$semester.'&CAMPUSID=1&LEVELID=&coursecode=*&coursename=&cmd=2';
    $dom->loadFromUrl($url);

    $contents = $dom->find('tr.normaldetail');
    $courseArray = [];
    foreach ($contents as $content)
    {
        $courseObj = [];
        $courseObj['acadYear'] = $year;
        $courseObj['semester'] = $semester;
        $tds = $content->find('td');

        $url2  = 'http://reg.buu.ac.th/registrar/'.$tds[1]->find('a')->getAttribute('href');
        
        $courseObj['courseId'] = intval(get_string_between($url2,'courseid=','&acadyear='));
        $dom2 = new simple_html_dom();
        $dom2->load_file($url2);
        $courseObj['courseCode'] =  intval($tds[1]->find('a')->innerHtml);
        $courseObj['courseName']  = $dom2->find('table[class="normaldetail"]')[0]->find('tr')[1]->find('td')[1]->plaintext; //courseName
        //teacher
        $lis  = $tds[2]->find('li');
        $teachers = [];
        if(count($lis) > 0){
            $teachers = '<li>'.$lis->innerHtml;
            $teachers = refixNoise($teachers,'ขจัดภั','ขจัดภัย');
            $teachers = refixNoise($teachers,'ชาติไท','ชาติไทย');
            $teachers = refixNoise($teachers,'เชยศุ กตุ','เชยศุภเกตุ');
            $teachers = removeNoise($teachers,['อาจารย์','MR.','ว่าที่เรือตรี','ผู้ช่วยศาสตราจารย์ ดร.','รองศาสตราจารย์ ดร.','ผู้ช่วยศาสตราจารย์','รองศาสตราจารย์','ดร.','</li>']);
            
            $teachers = splitTeacher($teachers);
        }
        $tds[2]->find('font')->delete();
        //endteacher
        $courseObj['courseNameEng']  = removeNoise($tds[2]->innerHtml,['<br />']); //courseNameEng
        $section = explode(' ',$tds[3]->innerHtml);//crepit(period)
        $periods = explode('-',$section[1]);
        $courseObj['credit'] = intval($section[0]);
        $courseObj['period1'] = intval(substr($periods[0],1));
        $courseObj['period2'] = intval($periods[1]);
        $courseObj['period3'] = intval(substr($periods[2],0,-1));
        $courseObj['section']  = removeNoise($tds[5]->innerHtml,['&nbsp;']); // group
        $courseObj['enrollSeat']  = intval(removeNoise($tds[7]->innerHtml,['&nbsp;'])); //enrollseat

        
        $times = removeNoise($tds[4]->innerHtml,
                                            [
                                            '<font face="tahoma" size="1" color="#A00000">',
                                            '<font face="tahoma" size="1" color="#808080">',
                                            '<font face="tahoma" size="1" color="#5080E0">',
                                            '</font>','<br />','<b>'
                                            ]);
        $times = splitTime($times);
        $courseObj['times'] = [];
        foreach($times as $time){
            $time = explode("##",$time);
            $time[1] = explode('-',$time[1]);
            $courseObj['times'][]= [
                            'courseCode'=>substr($courseObj['courseCode'],0,6),
                            'day'=>$time[0],
                            'startTime'=>$time[1][0],
                            'finishTime'=>$time[1][1],
                            'room'=>$time[2]];
        }
        $actual_group = "";
        $levelName='';
        foreach($dom2->find('table[class="normaldetail"]')[1]->find('tr[bgcolor=#F5F5F5],tr[bgcolor=#F5f5f5],tr[bgcolor=#FFFFF0]') as  $sec){
            if($sec->bgcolor == '#FFFFF0'){
                $levelName =  $sec->find('td')[1]->find('font')[0]->plaintext;
                continue;
            }
            $group = removeNoise($sec->find('td')[1]->plaintext,['<b>','</b>','&nbsp;']);
            if($group != ''){
                $actual_group = intval($group);
            }
            if($actual_group == removeNoise($tds[5]->innerHtml,['&nbsp;'])){
                if(count($sec->find('td[colspan=5]'))==0){
                    $tmptd = $sec->find('td');
                    foreach($courseObj['times'] as &$time){
                        $courseObj['levelName'] = $levelName;
                        $courseObj['levelId'] = levelNameToId($levelName);
                        if((dateEngToThai($time['day']) == $tmptd[3]->plaintext) && ($time['startTime'].'-'.$time['finishTime'] == $tmptd[4]->plaintext)){
                            
                            $time ['studyType'] = ($tmptd[7]->plaintext == 'C'?'lecture':($tmptd[7]->plaintext == 'L'?'lab':''));
                            $time ['day'] = dateEngToThaiNoi($time['day']);
                            break;
                        }
                    }
                }
            }
            
        }
        $courseObj['teachers'] = $teachers;
        
        $courseArray[]=json_decode(json_encode($courseObj),true);
    }
    return $courseArray;
}
function refixNoise($string,$find,$replace){
    return str_replace($find,$replace,$string);
}
function explode_new($text,$suflixs){
    $text = preg_replace($suflixs, "|", $text);
    $text = explode("|",$text);
    foreach($text as $key => $value){
        if($value =='')
        array_splice($text, $key, 1);
    }
    return $text;
}
function removeNoise($text,$noises){
    foreach ($noises as $noise) {
        $text = str_replace($noise,'',$text);
    }
    return $text;
}
function splitTeacher($teachers){
    $teachers = explode("<li>",$teachers);
    array_splice($teachers,0,1);
    return $teachers;
}
function splitTime($times){
    $times = str_replace("</u>","|",$times);
    $times = str_replace([' <u>','</b>'],'##',$times);
    $times = substr($times,0,-1);
    $time  = explode("|",$times);
    if(count($time) > 0 && $time[0] != null)
    return $time;
    return [];
}
function splitUrlToArray($url){
    $remove_http = str_replace('http://', '', $url);
    $split_url = explode('?', $remove_http);
    $get_page_name = explode('/', $split_url[0]);
    $page_name = $get_page_name[1];

    $split_parameters = explode('&', $split_url[1]);

    for($i = 0; $i < count($split_parameters); $i++) {
        $final_split = explode('=', $split_parameters[$i]);
        $split_complete[$page_name][$final_split[0]] = $final_split[1];
    }
    return $split_complete;
}

function dateEngToThai($day){
 switch ($day) {
        case 'MO':
          return 'จันทร์';
          break;
        case 'TU':
          return 'อังคาร';
          break;
        case 'WE':
          return 'พุธ';
          break;
        case 'TH':
          return 'พฤหัสบดี';
          break;
        case 'FR':
          return 'ศุกร์';
          break;
        case 'SA':
          return 'เสาร์';
          break;
        case 'SU':
          return 'อาทิตย์';
          break;
        default:
          return '';
          break;
      }
}

function dateEngToThaiNoi($day){
    switch ($day) {
        case 'MO':
          return 'จ.';
          break;
        case 'TU':
          return 'อ.';
          break;
        case 'WE':
          return 'พ.';
          break;
        case 'TH':
          return 'พฤ.';
          break;
        case 'FR':
          return 'ศ';
          break;
        case 'SA':
          return 'ส.';
          break;
        case 'SU':
          return 'อา.';
          break;
        default:
          return '';
          break;
      }
}
function get_string_between($string, $start, $end){
    $string = ' ' . $string;
    $ini = strpos($string, $start);
    if ($ini == 0) return '';
    $ini += strlen($start);
    $len = strpos($string, $end, $ini) - $ini;
    return substr($string, $ini, $len);
}

function preprocessCourses($courses){
    $courseArray = [];
    foreach($courses as $course){
        foreach($course['times'] as $time){
            $tmpcourse = $course;
            unset($tmpcourse['times']);
            $tmpcourse['time']= $time;
            $courseArray[] = $tmpcourse;
        }
    }
    $courseArrayByTime = [];
    foreach($courseArray as $course){
        $courseArrayByTime[json_encode($course['time'])][] = $course; 
    }
    $courseArrayByGroup = [];
    foreach($courseArrayByTime as $courses){
        
        $tmpcourse = $courses[0];
        $tmpcourse['courseCodes'] = array_unique(array_column($courses,'courseCode'));
        $tmpcourse['enrollSeat'] = array_sum(array_column($courses,'enrollSeat'));
        $tmpcourse['sections'] = array_column($courses,'section');
        $tmpcourse['section'] = implode(',',$tmpcourse['sections']);
        $tmpcourse['roomCode'] = $tmpcourse['time']['room'];
        if(!isset($tmpcourse['time']['studyType'])){
            echo "case:time";
            var_dump($courses[0]);
        }
        $tmpcourse['studyType'] = $tmpcourse['time']['studyType']??'';
        $tmpcourse['teacher'] = array_unique(mergeMultiArray(array_column($courses,'teachers')));
        foreach($tmpcourse['teacher'] as &$teacher){
            $tmpTeacher = explode(" ",$teacher);
            while($tmpTeacher[0] == ""){
                array_splice($tmpTeacher,0,1);
            }
            if(isset($tmpTeacher[2])){
                $tmpTeacher[1] = $tmpTeacher[1].' '.$tmpTeacher[2];
            }
            $teacher = ['officerName'=>$tmpTeacher[0],'officerSurname'=>$tmpTeacher[1]];
        }
        $tmpcourse['enrollSeats'] = arrayMultiColumn($courses,['enrollSeat','section','levelId','levelName']);
        
        unset($tmpcourse['teachers']);
        unset($tmpcourse['time']['studyType']);
        unset($tmpcourse['time']['room']);
        unset($tmpcourse['time']['courseCode']);
        $tmpcourse['day'] = $tmpcourse['time']['day'];
        $tmpcourse['startTime'] = $tmpcourse['time']['startTime'];
        $tmpcourse['finishTime'] = $tmpcourse['time']['finishTime'];
        $tmpcourse['creditLecture'] = $tmpcourse['period1'];
        $tmpcourse['creditLab'] = $tmpcourse['creditLecture'] - $tmpcourse['creditLecture'];
        $tmpcourse['display'] = true;
        $tmpcourse['canEdit'] = true;
        $tmpcourse['teachInEng'] = false;
        
        $courseArrayByGroup[] = $tmpcourse;

    }

    $coursesByKey = [];
    foreach($courseArrayByGroup as $course){
        $coursesByKey[json_encode(['courseCode'=>$course['courseCodes'],'sections'=>$course['sections']])][]=$course;
    }
    $courses = [];
    foreach($coursesByKey as $courseArrayByGroup){
        $course = $courseArrayByGroup[0];
        $course['rooms'] = array_column($courseArrayByGroup,'roomCode');
        $course['times'] = array_column($courseArrayByGroup,'time');
        unset($course['time']);

        $courses[] = $course;
    }
    return $courses;
}

function mergeMultiArray($array1){
    $result = array();
    foreach ($array1 as $subarray) {
        $result = array_merge($result, $subarray);
    }
    return $result;
}
function arrayMultiColumn($arrays,$column){
    $result = [];
    foreach($arrays as $array){
        $columns = [];
        foreach($column as $key){
            if(!isset($array[$key])){
                echo "case:levelName";
                var_dump($array);
            }
            $columns[$key] = $array[$key]??'';
        }
        $result[] = $columns;
    }
    return $result;
}
function levelNameToId($levelName){
    switch ($levelName) {
        case "ปริญญาตรี ปกติ":
          return 1;
          break;
        case "ปริญญาตรี พิเศษ":
          return 2;
          break;
        case "ปริญญาโท ปกติ":
          return 12;
          break;
        case "ปริญญาโท พิเศษ":
          return 13;
          break;
        case "ปริญญาเอก ปกติ":
          return 14;
          break;
        case "ปริญญาเอก พิเศษ":
          return 15;
          break;
        default:
          return 0;
          break;
      }
}
