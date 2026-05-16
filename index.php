<?php
include("dup.php");
$dup = new dup($profile);
$Profiles = $dup->GetProfiles("id");
LogStr("Home", 1, __FILE__, __LINE__, __FUNCTION__);

function DupHtml($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES, "UTF-8");
}

function DupInt($value)
{
    return intval($value);
}

?>

<!DOCTYPE HTML>
<HTML>
 <HEAD>
  <TITLE>dup - Home</TITLE>
  <META CHARSET="utf-8">
  <META CONTENT="IE=edge,chrome=1" HTTP-EQUIV="X-UA-Compatible">
  <META NAME="robots" CONTENT="noindex">
  <!--[if lt IE 9]>
  <SCRIPT SRC="files/html5.js"></SCRIPT>
  <![endif]-->
  <LINK REL="shortcut icon" HREF="images/dup.png">
  <LINK REL="stylesheet" TYPE="text/css" HREF="files/bootstrap.css">  
  <LINK REL="stylesheet" TYPE="text/css" HREF="files/jquery-ui-1.8.24.custom.css">
  <LINK REL="stylesheet" TYPE="text/css" HREF="files/style.css"> 
  <LINK REL="stylesheet" TYPE="text/css" HREF="files/imgs.css">  
  <SCRIPT TYPE="text/javascript" SRC="files/jquery-1.7.2.min.js"></SCRIPT>
  <SCRIPT TYPE="text/javascript" SRC="files/jquery-ui-1.8.24.custom.min.js"></SCRIPT>
  <SCRIPT TYPE="text/javascript" SRC="files/jquery.tablesorter-2.1.19.min.js"></SCRIPT>
  <SCRIPT SRC="files/script.js"></SCRIPT>  
  <SCRIPT TYPE="text/javascript" CHARSET="utf-8">
        sbRoot = '/'; 
        top_image_html = '<img src="images/top.gif" style="width:31px; height:11px" alt="Jump to top" />'; 
  </SCRIPT>
  <SCRIPT TYPE="text/javascript" SRC="files/jquery.scrolltopcontrol-1.1.js"></SCRIPT>  
  <SCRIPT TYPE="text/javascript">
    <!--
        $(document).ready(function(){ 
            $("#NAVhome").addClass("active");
        });
    //-->
  </SCRIPT>
  <script TYPE="text/javascript">  
function PostProfileAction(url, id)
{
    var form = document.createElement("form");
    form.method = "POST";
    form.action = url;

    var input = document.createElement("input");
    input.type = "hidden";
    input.name = "profile";
    input.value = id;

    form.appendChild(input);
    document.body.appendChild(form);
    form.submit();
}

function handleClick(id, act, title)
{
    if(act == "Delete")
    {
        var answer = confirm("Are you sure you want to delete profile:\n" + title + "\n\nThis cannot be undone.");

        if(answer == true)
            PostProfileAction("delete-profile.php", id);
    }
    else if(act == "Edit")
    {
        window.location.href = "profile-settings.php?profile=" + encodeURIComponent(id);
    }
    else if(act == "Scan")
    {
        var answer = confirm("Are you sure you want to scan profile:\n\n" + title + "\n\nThis may take some time but will run in the background.");

        if(answer == true)
            PostProfileAction("scan-files.php", id);
    }
}
  </SCRIPT>   
  <SCRIPT TYPE="text/javascript" charset="utf-8">
    <!--
    $.tablesorter.addParser({ 
        id: 'eps',
        is: function(s) {
            return false; 
        },
        format: function(s) {
            match = s.match(/^(.*)/);

            if (match == null || match[1] == "?")
                return -10;

            var nums = match[1].split(" / ");
            nums[0]=parseInt(nums[0].replace(/\,/g,''),10);
            nums[1]=parseInt(nums[1].replace(/\,/g,''),10);

            if (parseInt(nums[0]) === 0)
                return parseInt(nums[1]);

            var finalNum = parseInt((nums[0]/nums[1])*1000)*100;
            if (finalNum > 0)
                finalNum += parseInt(nums[0]);

            return finalNum;
        },
        type: 'numeric'
    });

    $(document).ready(function(){ 

        $("#showListTable:has(tbody tr)").tablesorter({
            sortList: [[1,1]],
            textExtraction: {
                4: function(node) { return $(node).find("div").text(); },
            },
            widgets: ['saveSort', 'zebra', 'stickyHeaders'],
            headers: {
                0: { sorter: 'isoDate' },
                4: { sorter: 'eps' }
            }
        });

    });
    //-->
  </SCRIPT>
  <META NAME=GENERATOR CONTENT="MSHTML 8.00.7601.17940">
 </HEAD>
 <BODY ONLOAD="autoupdate();">
 
  <?php echo PageHeader(); ?>  
   <DIV ID=SubMenu><span><A href="profile-add.php"><b>Add Profile</b></A></span></DIV>
  </HEADER>

  <DIV id=contentWrapper>
   <DIV id=content>
    <H1 class=title>Profiles</H1>
    <TABLE id=showListTable class=tablesorter border=0 cellSpacing=1 cellPadding=0>
     <THEAD>
      <TR>
       <TH class=nowrap>Last Run</TH>
       <TH>Profile</TH>
       <TH>Type</TH>
       <TH>Hash</TH>
       <TH>Dups/Files</TH>
       <TH>Options</TH>
       <TH>Status</TH>
      </TR>
     </THEAD>
     <TFOOT>
      <TR>
       <TH style="color: black; background-color:#dfdacf; border-bottom: 1pt solid black; border-top: 1pt solid black; border-left: 1pt solid black; border-right: 1pt solid black;">Last Run</TH>
       <TH style="color: black; background-color:#dfdacf; border-bottom: 1pt solid black; border-top: 1pt solid black; border-left: 1pt solid black; border-right: 1pt solid black;">Profile</TH>
       <TH style="color: black; background-color:#dfdacf; border-bottom: 1pt solid black; border-top: 1pt solid black; border-left: 1pt solid black; border-right: 1pt solid black;">Type</TH>
       <TH style="color: black; background-color:#dfdacf; border-bottom: 1pt solid black; border-top: 1pt solid black; border-left: 1pt solid black; border-right: 1pt solid black;">Hash</TH>
       <TH style="color: black; background-color:#dfdacf; border-bottom: 1pt solid black; border-top: 1pt solid black; border-left: 1pt solid black; border-right: 1pt solid black;">Dups/Files</TH>
       <TH style="color: black; background-color:#dfdacf; border-bottom: 1pt solid black; border-top: 1pt solid black; border-left: 1pt solid black; border-right: 1pt solid black;">Options</TH>
       <TH style="color: black; background-color:#dfdacf; border-bottom: 1pt solid black; border-top: 1pt solid black; border-left: 1pt solid black; border-right: 1pt solid black;">Status</TH>
      </TR>
     </TFOOT>
     <TBODY>
 
<?php
foreach($Profiles as $key => $value)
{
    $profileRowId = DupInt($value['id']);
    $filesCount = DupInt($value['files']);
    $dupsCount = DupInt($value['dups']);
    $runsCount = DupInt($value['runs']);
    $crcMode = DupInt($value['crc32']);

    $hash = "Size";

    switch($crcMode)
    {
        case 0: $hash = "Size"; break;
        case 1: $hash = "CRC32"; break;
        case 2: $hash = "CRC32 Full"; break;
        default: $hash = "Size"; break;
    }

    $newval = $filesCount - $dupsCount;

    if($newval < 0)
        $newval = 0;

    $progressValue = 0;

    if($filesCount > 0)
        $progressValue = intval(($newval * 100) / $filesCount);

    $profilePath = isset($value['path']) ? $value['path'] : "";
    $profilePathHtml = DupHtml($profilePath);
    $profilePathJs = SafeJavaValue($profilePath);
    $lastRunHtml = DupHtml(isset($value['lastrun']) ? $value['lastrun'] : "");
    $profileTypeHtml = DupHtml(GetProfileType(isset($value['type']) ? $value['type'] : 0));

    echo " <TR>\n";
    echo " <TD class=\"nowrap\" align=\"middle\">" . $lastRunHtml . "</TD>\n";
    echo " <TD class=\"tvShow\"><A href=\"profile-view.php?profile=" . $profileRowId . "\">" . $profilePathHtml . "</A></TD>\n";
    echo " <TD class=\"nowrap\" align=\"middle\">" . $profileTypeHtml . "</TD>\n";
    echo " <TD align=\"middle\">" . DupHtml($hash) . "</TD>\n";
    echo " <TD align=\"middle\"> <div style=\"DISPLAY: none\">" . $filesCount . " / " . $dupsCount . "</div>\n";
    echo " <DIV style=\"POSITION: relative\" id=\"progressbar" . $profileRowId . "\"></DIV>\n";
    echo " <SCRIPT type=\"text/javascript\">\n";
    echo " $(function() {\n";
    echo "     $(\"#progressbar" . $profileRowId . "\").progressbar({ value: " . $progressValue . " });\n";
    echo "     $(\"#progressbar" . $profileRowId . "\").append(\"<div class='progressbarText'>" . number_format($dupsCount) . " / " . number_format($filesCount) . "</div>\");\n";
    echo " });\n";
    echo " </SCRIPT>\n";
    echo " </TD>\n";
    echo " <TD align=\"middle\">" . $runsCount . " / <a href=\"javascript:handleClick('" . $profileRowId . "', 'Delete', '" . $profilePathJs . "')\"><I class=\"icon-trash\" title=\"Delete\"></I></a> / <a href=\"javascript:handleClick('" . $profileRowId . "', 'Edit', '')\"><I class=\"icon-edit\" title=\"Edit\"></I></a> / <a href=\"javascript:handleClick('" . $profileRowId . "', 'Scan', '" . $profilePathJs . "')\"><I class=\"icon-eye-close\" title=\"Scan\"></I></a></TD>\n";

    $stats = GetStat($profileRowId);
    $statValueHtml = DupHtml(isset($stats['value']) ? $stats['value'] : "");
    $statDataHtml = DupHtml(isset($stats['data']) ? $stats['data'] : "");

    echo " <TD align=\"middle\"><span id=\"profile" . $profileRowId . "\" title=\"" . $statDataHtml . "\">" . $statValueHtml . "</span></TD>\n";
    echo " </TR>\n";
}
?> 

     </TBODY> 
    </TABLE> 
   </DIV><!-- /content -->
  </DIV><!-- /contentWrapper -->
  
<?php echo PageFooter(); ?>
  
<SCRIPT type="text/javascript">
function update()
{
    $.ajax({
        url: "status-update.php",
        cache: false,
        dataType: "json",
        success: function(response)
        {
            if(!$.isArray(response))
                return;

            var shouldContinue = false;
            var shouldReload = false;

            for(var i = 0; i < response.length; i++)
            {
                var id = "profile" + response[i]["profile"];
                var node = document.getElementById(id);

                if(!node)
                    continue;

                var oldValue = node.innerHTML;
                var newValue = response[i]["value"];
                var newData = response[i]["data"];

                node.innerHTML = newValue;
                node.title = newData;

                if(newValue != "Idle" && newValue != "")
                    shouldContinue = true;

                if(oldValue != "Idle" && oldValue != "" && newValue == "Idle")
                    shouldReload = true;
            }

            if(shouldReload)
                window.location.reload();
            else if(shouldContinue)
                setTimeout(update, 3000);
        }
    });
}

function autoupdate()
{
    var myDiv = document.getElementById("showListTable");

    if(!myDiv)
        return;

    var nodes = myDiv.getElementsByTagName("span");

    for(var index = 0; index < nodes.length; index++)
    {
        var node = nodes[index];

        if(node.id.length > 0)
        {
            if(node.innerHTML != "Idle" && node.innerHTML != "")
            {
                update();
                return;
            }
        }
    }
}

$(document).ready(function() {
    autoupdate();
});
</SCRIPT>
<?php
function GetProfileType($type)
{
    global $dblink;

    $type = intval($type);
    $value = "Unknown";

    if($type <= 0)
        return $value;

    try
    {
        $query = "SELECT name FROM profiletypes WHERE id=:id LIMIT 1;";
        LogStr("MySQL/SQLite: $query", 3, __FILE__, __LINE__, __FUNCTION__);

        $sdt = $dblink->prepare($query);
        $sdt->bindValue(":id", $type, PDO::PARAM_INT);
        $sdt->execute();

        $row = $sdt->fetch(PDO::FETCH_ASSOC);

        if($row && isset($row['name']))
            $value = $row['name'];

        $sdt->closeCursor();
    }
    catch(PDOException $ex) {
        LogError(print_r($ex, true));
        header("Location: error.php");
        exit;
    }

    return $value;
}
?> 
 </BODY>
</HTML>
