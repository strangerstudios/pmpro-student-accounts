<?php
/*
Plugin Name: Paid Memberships Pro - Student Accounts
Plugin URI: https://www.paidmembershipspro.com/add-ons/pmpro-student-accounts/
Description: Create student accounts tied to membership levels.
Version: .1
Author: Stranger Studios
Author URI: http://www.strangerstudios.com
*/

/*
	Define your main levels and student account levels
	along with how many logins each gets.
*/
global $pmpro_student_account_levels;
$pmpro_student_account_levels = array(
	5 => array(
		'main_level_id' => 5,
		'student_level_id' => 13,
		'num_logins' => 50,
	),
	6 => array(
		'main_level_id' => 6,
		'student_level_id' => 13,
		'num_logins' => 130,
	),
        16 => array(
		'main_level_id' => 16,
		'student_level_id' => 13,
		'num_logins' => 20,
	),
);

/*
	Add student account password to checkout and profile.
	
	Requires PMPro Register Helper
*/
function pmprosa_register_helper_fields()
{
	//don't break if Register Helper or PMPro is not loaded
    if(!function_exists("pmprorh_add_registration_field") || !function_exists("pmpro_getMembershipLevelForUser"))
        return false;
		
	//define the fields
    $fields = array();
    $fields[] = new PMProRH_Field(
        "student_pass",              // input name, will also be used as meta key
        "text",                 // type of field
        array(
            "size"=>20,         // input size            
            "label"=>"Student Password",
			"profile"=>true,    // show in user profile
            "required"=>true,    // make this field required
			"levels"=>array(5,6,16),
			"save_function" => "pmprosa_save_student_pass",
        ));
		
	//add the fields into a new checkout_boxes are of the checkout page
    pmprorh_add_checkout_box("student_account", "Student Account Information");	
	foreach($fields as $field)
        pmprorh_add_registration_field(
            "student_account", // location on checkout page
            $field            // PMProRH_Field object
        );
}
add_action('init', 'pmprosa_register_helper_fields');

/*
	Create the student account when changing levels.
*/
function pmprosa_pmpro_after_change_membership_level($level_id, $user_id)
{
	global $pmpro_student_account_levels;

	$user = get_userdata($user_id);
	$student_pass = $user->student_pass;
	$student_user = $user->user_login . "-stu";
	
	//student level or not?
	if(empty($pmpro_student_account_levels[$level_id]))
	{
		//not a teacher/student level, delete any existing student level
		$duser = get_user_by('login', $student_user);
		if(!empty($duser->ID))
		{
			require_once(ABSPATH.'wp-admin/includes/user.php' );
			wp_delete_user($duser->ID);
		}
	}
	else
	{	
		//student/teacher level, add user if it doesn't exist
		$suser = get_user_by('login', $student_user);
		if(empty($suser->ID))
		{
			$student_id = wp_insert_user(array('user_login'=>$student_user, 'user_pass'=>$student_pass, 'role'=>'student', 'user_email'=>str_replace("@", "+s@", $user->user_email)));
			$suser = get_userdata($student_id);
		}
		else
		{
			//make sure password is correct
			wp_set_password($student_pass, $suser->ID);
		}
	
		//give student level
		pmpro_changeMembershipLevel($pmpro_student_account_levels[$level_id]['student_level_id'], $suser->ID);
	}
}
add_action('pmpro_after_change_membership_level', 'pmprosa_pmpro_after_change_membership_level', 20,2 );

/*
	Update student password when saving teacher profile.
*/
function pmprosa_save_student_pass($user_id, $name, $value)
{
	//if changed, update user
	$old_student_pass = get_user_meta($user_id, "student_pass", true);	
	if($old_student_pass !== $value)
	{
		$suser = pmprosa_getStudentFromTeacherID($user_id);
		if(!empty($suser))
		{
			wp_set_password($value, $suser->ID);
		}
	}
	
	update_user_meta($user_id, $name, $value);	
}

/*
	Set WP Bouncer limit based on parent account.
*/
function pmprosa_wp_bouncer_number_simultaneous_logins($num)
{
	//in case pmpro sponsored members or pmpro is deactivated or something
	if(!function_exists('pmpro_getMembershipLevelForUser'))
		return $num;

	global $pmpro_student_account_levels, $current_user;
		
	/*
		Set limit for main teacher accounts?
	*/
		
	/*
		Set limit for students
	*/
	$teacher = pmprosa_getTeacherFromStudentID($current_user->ID);
	if(!empty($teacher) && !empty($teacher->ID))
	{
		$teacher_level = pmpro_getMembershipLevelForUser($teacher->ID);
		
		if(!empty($teacher_level) && !empty($pmpro_student_account_levels[$teacher_level->id]))
		{
			$num = $pmpro_student_account_levels[$teacher_level->id]['num_logins'];
		}
	}

	return $num;
}
add_filter('wp_bouncer_number_simultaneous_logins', 'pmprosa_wp_bouncer_number_simultaneous_logins');

/*
	Get teacher user from student.
*/
function pmprosa_getTeacherFromStudentID($student_id)
{
	$student = get_userdata($student_id);
	
	if(empty($student) || empty($student->ID))
		return false;
	
	return get_user_by('login', str_replace("-stu", "", $student->user_login));
}

/*
	Get student user from teacher.
*/
function pmprosa_getStudentFromTeacherID($teacher_id)
{
	$teacher = get_userdata($teacher_id);
	
	if(empty($teacher) || empty($teacher->ID))
		return false;
	
	return get_user_by('login', $teacher->user_login . "-stu");	
}

/*
	Delete student account when main account is deleted.
*/
function pmprosa_delete_user($user_id)
{
	$user = get_userdata($user_id);
	
	$suser = get_userdata($user->user_login . "-stu");
	if(!empty($suser) && !empty($suser->ID))
		wp_delete_user($suser->ID);
}
add_action('delete_user', 'pmprosa_delete_user');

/*
	Show student account info on confirmation page.
*/
//show a user's discount code on the confirmation page
function pmprosa_pmpro_confirmation_message($message)
{
	global $current_user, $wpdb, $pmpro_student_account_levels;
	
	$level = pmpro_getMembershipLevelForUser($current_user->ID);
		
	if(!empty($pmpro_student_account_levels[$level->id]))
	{	
		//look for student account
		$student = pmprosa_getStudentFromTeacherID($current_user->ID);
				
		if(!empty($student) && !empty($student->ID))
		{
			$message .= "<div class=\"pmpro_content_message\">
				<p>Here is the username and password for your student account:</p>
				<p>
					<strong>Username: </strong>" . $student->user_login . "<br />
					<strong>Password: </strong>" . $current_user->student_pass . "
				</p>
			</div>";
		}
	}
	
	return $message;
}
add_filter("pmpro_confirmation_message", "pmprosa_pmpro_confirmation_message");

/*
	Show student account info in confirmation email.
*/
function pmprosa_pmpro_email_body($body, $pmpro_email)
{
	global $wpdb, $pmpro_student_account_levels;
 
	//only checkout emails, not admins
	if(strpos($pmpro_email->template, "checkout") !== false && strpos($pmpro_email->template, "admin") == false)
	{ 
		//get the user_id from the email
		$user_id = $wpdb->get_var("SELECT ID FROM $wpdb->users WHERE user_email = '" . $pmpro_email->data['user_email'] . "' LIMIT 1");
		$level_id = $pmpro_email->data['membership_id'];
		
		if(!empty($user_id) && !empty($pmpro_student_account_levels[$level_id]))
		{
			//get user
			$user = get_userdata($user_id);
			
			//look for student account
			$student = pmprosa_getStudentFromTeacherID($user->ID);
			
			if(!empty($student) && !empty($student->ID))
			{
				$message .= "<div class=\"pmpro_content_message\">
					<p>Here is the username and password for your student account:</p>
					<p>
						<strong>Username: </strong>" . $student->user_login . "<br />
						<strong>Password: </strong>" . $user->student_pass . "
				</p>
				</div>";
				
				$body = $message . "<hr />" . $body;
			}						
		}
	}
 
	return $body;
}
add_filter("pmpro_email_body", "pmprosa_pmpro_email_body", 10, 2);

/*
	Show student account info on account page.
*/
function pmprosa_the_content_account_page($content)
{
	global $post, $pmpro_pages, $current_user, $wpdb, $pmpro_student_account_levels;
			
	if(!is_admin() && $post->ID == $pmpro_pages['account'])
	{
		//what's their code?
		$level = pmpro_getMembershipLevelForUser($current_user->ID);		
		
		if(!empty($level) && !empty($pmpro_student_account_levels[$level->id]))
		{			
			//look for student account
			$student = pmprosa_getStudentFromTeacherID($current_user->ID);
			
			if(!empty($student) && !empty($student->ID))
			{					
				ob_start();						
				?>
				<div id="pmpro_account-student" class="pmpro_box">	
					 
					<h3><?php _e("Student Account Information", "pmprosa");?></h3>
										
					<ul>
						<li><strong>Username: </strong><?php echo $student->user_login; ?></li>
						<li><strong>Password: </strong><?php echo $current_user->student_pass; ?></li>
					</ul>
					
				</div> <!-- end pmpro_account-sponsored -->
				<?php
				
				$temp_content = ob_get_contents();
				ob_end_clean();
						
				$content = str_replace('<!-- end pmpro_account-profile -->', '<!-- end pmpro_account-profile -->' . $temp_content, $content);
			}
		}			
	}
	
	return $content;
}
add_filter("the_content", "pmprosa_the_content_account_page", 30);