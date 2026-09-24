<?php require '../ea.php'; ?>
<?php require './emailverify.php'; ?>
<?php
// customoptions/index.php
// post and get activist codes to EveryAction via API
// Fields
//  - VanID *required c=
//  - Email em = 
//  - Email Distribution ID emdi=
//  - Type type=
//  - First_Name fn=
//  - Last_Name ln=

session_start();

$referer = !empty(getenv("HTTP_REFERER")) ?  getenv("HTTP_REFERER") : NULL;
$ip = !empty($_SERVER["REMOTE_ADDR"]) ? $_SERVER["REMOTE_ADDR"] : "";
$request_method = $_SERVER['REQUEST_METHOD'];

// Get inputs
// exit if email message identifier
$emdi = filter_var(test_input($_REQUEST['emdi']), FILTER_SANITIZE_STRING);
$emdi_valid = (empty($emdi) || strlen($emdi) < 15 || $emdi==false);
// if (empty($emdi) || strlen($emdi) < 15 || $emdi==false) {
//     echo "Missing or invalid emailid";
//     exit();
// }

//Exit if vanid is empty or not present
$vanid = filter_var(test_input($_REQUEST['c']), FILTER_VALIDATE_INT);
$vanid_valid = (empty($vanid) || strlen($vanid) < 3 || $vanid==false);
// if (empty($vanid) || strlen($vanid) < 3 || $vanid==false) {
//     echo "Missing or invalid id";
//     exit();
// }

$email = filter_var(test_input($_REQUEST['em']), FILTER_SANITIZE_EMAIL);
$email_valid = filter_var($email,FILTER_VALIDATE_EMAIL);

$email_hash_verify = filter_var(test_input($_GET['v']), FILTER_SANITIZE_STRING);
if ($email_hash_verify) {
   
    $r = get_by_hash($email_hash_verify, 1);
    if ($r) {
        $email = $r["email"];
        $vanid = $r["vanid"];
        $created_at = $r["created_at"];
    } else {
        $email_hash_verify = false;
    }

}

// ctype is the type of display currently availablae are lists, actions, events
$ctype = filter_var(test_input($_GET['type']), FILTER_SANITIZE_STRING);

//used to have the ability to pass multiple keys of the same value 
function get_post_array() {
    $post = array();
    foreach (explode('&', file_get_contents('php://input')) as $keyValuePair) {
        list($key, $value) = explode('=', $keyValuePair);
        $post[$key][] = $value;
    }	
    return $post;
}

function test_input($data) {
    $data = trim($data);
    $data = stripslashes($data);
    $data = htmlspecialchars($data);
    return $data;
}

$activist_types_mapping = Array(
    //"Actions" => "A",
    "Lists" => "Lists",
    "Events" => "E",
    "Appeals" => "Ap",
    "Misc" => "Pub"
);

// List of EA activist code types to consider
$activist_code_types = Array(
    "Lists" => Array(),
    "Email Interests" => Array(),
    "Misc" => Array()
);

$vanid_activist_codes = Array();

// Verify EA API is working
$response = ea_echos();
if ($response == false) {
    echo "Service down";
    exit();
}

$salt = "YVuMvHDXUQwYCyZYdMAo";
$rand = rand(1000,5000); 
$hash = md5($ip.$salt.$vanid.$rand);
$hash = substr($hash,0,20);


if ($request_method==="GET") {

    $_SESSION['process_key']=$hash;

    if ($vanid) {
        $msg = null;
        // Get email array to validate emaildi
        $string = file_get_contents("https://cbddocs.blob.core.windows.net/forms/email_categories.json");
        if ($string===false) {
            $msg = "Email list is down";
            $$emdi = null;
        }
        $json_a = json_decode($string, true);

        $datestring = date('Y-m-d',strtotime("-2 days"));

        $source_message = null;
        foreach ($json_a as $value) {
            if ($value["DateSent"] >= $datestring && strtoupper($value["DistributionUniqueID"]) == strtoupper($emdi)) {
                $source_message = $value;
                break;
            }
        }
        $test_emdi = "ea000000-0000-0000-0000-000000000001";
        if (!$email_hash_verify && $source_message === null && $emdi !== $test_emdi) {
            $vanid = null;
            $$emdi = null;
            $msg = "invalid values passed in";
        }

        if ($vanid) {
            // - Get person info
            $json_result = ea_get_person($vanid);
            if (!$json_result) {
                $vanid = null;
                $$emdi = null;
                $msg = "account not found";
            }
            $person = $json_result;
        }

        if ($vanid) {

            // 2 - Get Codes associated with $vanid
            $json_result = ea_get_person_activist_codes($vanid);
            if (!$json_result) {
                exit();
            }
            // transfer to array of unique activist code ids for the user
            foreach ($json_result["items"] as $value) {
                if (!in_array($value["activistCodeId"], $vanid_activist_codes)) {
                    $vanid_activist_codes[] = $value["activistCodeId"];
                }
            }

            //3 - get activist codes all activist codes and group by type
            $json_result = ea_get_activist_codes();
            if (!$json_result || !array_key_exists("items", $json_result)) {
                print_r("Activist Codes not found.");

                if ($json_result && array_key_exists("errors", $json_result)) {
                    print_r($json_result["errors"]["code"]);
                }
                exit();
            }
            $activist_codes = $json_result["items"];

            // Modify Activist Codes to be grouped by Type of interest
            foreach ($activist_code_types as $type => $value) {
                foreach ($activist_codes as &$j) {
                    if ($j["type"] == $type) {
                        $type2 = $type;
                        // Load to lists when cateory type is Email Interests
                        if ($type == "Email Interests") {
                            $type2 = "Lists";
                        }
                        // Categorize by grouping if name is hyphenated
                        $tmp = explode("-", $j["name"], 2);
                        if (count((array)$tmp) > 1) {
                            $type2 = trim($tmp[0]);
                            $j["name"] = trim($tmp[1]);
                        }
                        $tmp = $j["description"];
                        if (count((array)$tmp) > 1) {
                            $j["description"] = trim($tmp[1]);
                        }

                        // note if activist code is set for user
                        $j["isset"] = false;
                        if (in_array($j["activistCodeId"], $vanid_activist_codes)) {
                            $j["isset"] = true;
                        }
                        $activist_code_types[$type2][] = $j;
                    }
                }
            }
        }
    }

} else {

    //First check hash
    $hash1 = $_POST['v'];
    if ($hash1 != $_SESSION['process_key'] || !isset($_SESSION['process_key'])) {
        exit();
    }
    unset($_SESSION['process_key']);

    if ($vanid) {

        $baseline = filter_var(test_input($_POST['baseline']), FILTER_SANITIZE_STRING);
        $baseline = explode(",", $baseline);

        foreach ($activist_types_mapping as $acname => $acvalue) {
            if (!empty($_POST[$acvalue])) {
                $vanid_activist_codes = array_merge($vanid_activist_codes, $_POST[$acvalue]);
            }
        }

        // Codes to remove are in $baseline and not in $vanid_activist_codes
        $remove = array_diff($baseline, $vanid_activist_codes);
        // Codes to add are in $vanid_activist_codes and not in
        $apply= array_diff($vanid_activist_codes, $baseline);

        if ($remove) {
            ea_update_person_activist_codes($vanid,$remove,$action="Remove");
        }
        if ($apply) {
            ea_update_person_activist_codes($vanid,$apply,$action="Apply");
        }
        $complete = true;   

    } else if ($email) {

        $ea_search_result = ea_find_person_by_emailaddress($email);
        $matched = $ea_search_result["status"];
        $vanid = $ea_search_result["vanId"];
        $person = ea_get_person($vanid);
        $firstname = $person["firstName"];
        $rand = rand(1000,5000); 
        $hash2 = md5($ip.$salt.$vanid.$rand);
        add_sql_table($hash2, $email, $vanid);
        send_verification_email($email, $vanid, $firstname, $hash2);

    }
}


?>


<!DOCTYPE html>
<html lang="en"><!-- InstanceBegin template="/Templates/cbd_single_template3.dwt" codeOutsideHTMLIsLocked="false" -->
  <head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="shortcut icon" href="/assets/ico/favicon.ico">
    <link rel="apple-touch-icon" sizes="57x57" href="/assets/ico/center-bug-rgb-57.png" />
    <link rel="apple-touch-icon" sizes="72x72" href="/assets/ico/center-bug-rgb-72.png" />
    <link rel="apple-touch-icon" sizes="114x114" href="/assets/ico/center-bug-rgb-114.png" />
    <link rel="apple-touch-icon" sizes="144x144" href="/assets/ico/center-bug-rgb-144.png" />
    <link
    href="https://nvlupin.blob.core.windows.net/images/van/CBD/CBD/1/61429/images/css/cbd-actions-events.css?v=2.16"
    rel="stylesheet" type="text/css">
<title>Communication Preferences</title>
	<?php     
    	//override any page variables here
    	$SIDE_BAR_CLASS='col-md-4';
        $SOURCEID=NULL;  
        $DONATE_PAGE=NULL;
        $DONATE_MESSAGE=NULL;
		$THANK_YOU=NULL; 
        $ACTIVIST_CODE_ID=NULL;       
    ?>  
    <?php
        $basepath = $_SERVER['DOCUMENT_ROOT'];
        include $basepath.'/assets/phtml/head-scripts.phtml';
    ?>
    <style>
        .btn-success {background-color: #0c6646}
    </style>
  </head>

  <body>
  <div class="container">

  <div class="clearfix"></div>

  <!-- Main banner picture content -->
  <div style="text-align:left">
      <div id="header-stripe" class="cbd-header-stripe">
          <div id="header-stripe-text">CENTER for BIOLOGICAL DIVERSITY</div>
      </div>
  </div>
  <!--END Main Banner picture content -->
</div>
<!--END Container -->
<div class="container">
  <!-- MAIN CONTENT STARTS HERE -->
  <noscript>
      <p>
        For full functionality of this site it is necessary to enable JavaScript.
        Here are the <a href="https://www.enable-javascript.com/" target="_blank">
            instructions how to enable JavaScript in your web browser</a>.
      </p>
  </noscript>

  <div class="clearfix"></div>

<div class="my-5 d-flex d-flex-row">
<div class="w-25 d-none d-sm-block"></div>
<?php if ($request_method=="GET") { ?>

    <div>
    <div class="mb-4">
    <H2><img src="../../../assets/img/action/eagle-wolf-whale.jpg" width="1140" height="330" alt="Eagle, wolf and whale" class="img-fluid"></H2>
    <H2>Communication Preferences</H2>
    <p>With the help of 1.7 million supporters, the Center for Biological Diversity works to secure a future for all species, great and small, hovering on the brink of extinction. Through science, law and creative media, in three decades we’ve saved more than 740 animals and plants and half a billion acres of habitat.
    </p>
    <p>You can be a part of the movement. Join us — and stay in touch.</p>
    <?php if ($vanid) { ?>
    <p><strong>Let us know how you want to hear from us using the checkboxes below.</strong></p>
    <?php } else { ?>
        <p><strong>Please enter and verify your email address to get started:</strong></p>
    <?php } ?>
    </div>

    <form method="POST" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]);?>">
    <?php 

    $baseline = Array();

    if ($vanid) {

    ?>
 

        <?php 
        foreach ($activist_types_mapping as $name => $shortcut) {
            if (($ctype && strtolower($name)!=strtolower($ctype) && strtolower($name)!="misc") || empty($activist_code_types[$shortcut])) {
                continue;
            }
        ?>

            <div class="mb-4">
            <H3><?=$name?></H3>
            <?php
                foreach ($activist_code_types[$shortcut] as $value) {
                    if ($value['isset']) {
                        $baseline[] = $value['activistCodeId'];
                    }
            ?>
                    <div class="form-check mb-2">
                    <input 
                        name="<?=$shortcut?>[]"
                        class="form-check-input" type="checkbox"
                        value="<?=$value['activistCodeId']?>"
                        id="<?=$value['activistCodeId']?>"
                        <?= $value['isset'] ? 'checked' : '' ?>
                    >
                        <label class="form-check-label" for="<?=$value['activistCodeId']?>">
                            <b><?=$value['name']?>:</b> <?=$value['description']?>
                        </label>
                    </div>
            <?php
                }
            ?>
            </div>

        <?php
        }
        ?>

    <?php
    } else {
    ?>
        <div class="form-group">
            <label for="email">Email address</label>
            <input type="email" class="form-control" id="email" name="em" required>
        </div>
    <?php
    }
    ?>

    <input type="hidden" name="c" value="<?=$vanid?>">
    <input type="hidden" name="emdi" value="<?=$emdi?>">
    <input type="hidden" name="type" value="<?=$ctype?>">
    <input type="hidden" name="baseline" value="<?=implode(',',$baseline)?>">
    <input type="hidden" name="v" value="<?=$hash?>">
    <button type="submit" class="btn btn-success">
        Submit
    </button>
    </form>
    <br>
    <a href="/action/forms/memberportal.html">Unsubscribe completely or update your personal information.</a>
    </div>

<?php } else { 
    if ($complete) { ?>
        Thank you!  Your preferences have been recorded.
<?php } else { ?>
        Please check your email for a verification link.
<?php }} ?>
<div class="w-25 d-none d-sm-block"></div>
</div>

  <!-- Footer -->
  <div id="footer" class="footer section-padding online-action" style="">
      <div id="social">&#160;
          <a class="social-link" target="_blank" rel="noopener noreferrer"
              href="https://www.facebook.com/CenterforBioDiv">
              <img src="https://nvlupin.blob.core.windows.net/images/van/CBD/CBD/1/61429/images/FacebookButton.png"
                  alt="Facebook" title="Like us on Facebook" style="display:inline-block;" border="0" width="35"
                  height="35" /></a> &#160;
          <a class="social-link" target="_blank" rel="noopener noreferrer" href="https://twitter.com/CenterForBioDiv">
              <img src="https://nvlupin.blob.core.windows.net/images/van/CBD/CBD/1/61429/images/TwitterButton.png"
                  alt="Twitter" title="Follow us on Twitter" style="display:inline-block;" border="0" width="35"
                  height="35" /></a> &#160;
          <a class="social-link" target="_blank" rel="noopener noreferrer"
              href="https://www.youtube.com/user/bioactivist">
              <img src="https://nvlupin.blob.core.windows.net/images/van/CBD/CBD/1/61429/images/YouTubeButton.png"
                  alt="YouTube" title="Visit our Youtube Channel" style="display:inline-block;" border="0" width="35"
                  height="35" /></a> &#160;
          <a class="social-link" target="_blank" rel="noopener noreferrer"
              href="http://instagram.com/centerforbiodiv">
              <img src="https://nvlupin.blob.core.windows.net/images/van/CBD/CBD/1/61429/images/InstagramButton.png"
                  alt="Instagram" title="Visit our Instagram page" style="display:inline-block;" border="0" width="35"
                  height="35" /></a> &#160;
          <a class="social-link" target="_blank" href="https://medium.com/center-for-biological-diversity"
              title="Follow us on Medium">
              <img src="https://nvlupin.blob.core.windows.net/images/van/CBD/CBD/1/61429/images/MediumButton.png"
                  alt="Medium" style="display:inline-block;" border="0" width="35" height="35" /></a>&#160;
          <br /><br />
          <span class="nobr" style="font-size: 18px; font-weight: bold;">
          
              <a target="_blank" href="https://biologicaldiversity.org">Center for Biological Diversity</a></span>
          &#160;&#160;&#160;|&#160;&#160;&#160; <span class="nobr" style="font-size: 18px; font-weight: bold;"><a
                  target="_blank" rel="noopener noreferrer" href="https://www.biologicaldiversity.org"
                  style="color:#ffffff">Saving Life on
                  Earth</a></span>
          <br /><br />
          <a href="https://act.biologicaldiversity.org/onlineactions/M4Ke4nY-aES3erNnNDL05w2?sourceid=1007193"
              target="_blank" rel="noopener noreferrer">Donate now to support the Center's work.</a><br />
          <br />
      </div>
      <p></p>
        <p><a href="https://www.biologicaldiversity.org" target="_blank" rel="noopener noreferrer">Center for Biological
                Diversity</a><br>
            PO Box 710, Tucson AZ 85702-0710<br>
            EIN: 27-3943866</p>
        <p>
            We take the privacy of our supporters seriously — access our 
            <a href="https://www.biologicaldiversity.org/privacy/" target="_blank" rel="noopener noreferrer">privacy policy</a>
            to learn more.
        </p>
        <p>Photo credits: Bald eagle via Pixabay; wolf pup courtesy Oregon Department of Fish and Game; humpback whale by Ed Lyman/NOAA.</p>
      <div class="formfooter"></div>
  </div>
  <!-- End Footer -->
</div>
<!-- END container -->

  </body>

