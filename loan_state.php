<!DOCTYPE HTML>
<?PHP
	require 'functions.php';
	require 'function_loans.php';
	checkLogin();
	$db_link = connect();
	getLoanID($db_link);

	$timestamp = time();

	// Select details of current loan from LOANS, LOANSTATUS, CUSTOMER
	$sql_loan = "SELECT * FROM loans JOIN loanstatus ON loans.loanstatus_id = loanstatus.loanstatus_id JOIN customer ON loans.cust_id = customer.cust_id WHERE loan_id = $_SESSION[loan_id]";
	$query_loan = mysqli_query($db_link, $sql_loan);
	checkSQL($db_link, $query_loan);
	$result_loan = mysqli_fetch_assoc($query_loan);
	$_SESSION['cust_id'] = $result_loan['cust_id'];

	// Get current customer's savings account balance
	$sav_balance = getSavingsBalance($db_link, $_SESSION['cust_id']);

	/** UPDATE STATUS Button **/
	if (isset($_POST['updatestatus'])){
		// Sanitize user input
		$loan_principal = $_SESSION['loan_principal'];
		$loan_interest = $_SESSION['loan_interest'];
		$loan_period = $_SESSION['loan_period'];
		$loan_issued = $_SESSION['loan_issued'];

		$loan_fee_receipt = sanitize($db_link, $_POST['loan_fee_receipt']);
		$loan_status = sanitize($db_link, $_POST['loan_status']);
		$loan_dateout = strtotime(sanitize($db_link, $_POST['loan_dateout']));
		$loan_princp_approved = sanitize($db_link, $_POST['loan_principalapproved']);

		if($loan_status == 2 AND $loan_issued == 0){

			//Include module for interest calculation method according to system settings
			include ($_SESSION['set_intcalc']);

			//Insert Loan Fee into INCOMES
			$loan_fee = $loan_princp_approved / 100 * $_SESSION['fee_loan'];
			$sql_inc_lf = "INSERT INTO incomes (cust_id, loan_id, inctype_id, inc_amount, inc_date, inc_receipt, inc_created, user_id) VALUES ('$_SESSION[cust_id]', '$_SESSION[loan_id]', '3', '$loan_fee', '$loan_dateout', '$loan_fee_receipt', '$timestamp', '$_SESSION[log_id]')";
			$query_inc_lf = mysqli_query($db_link, $sql_inc_lf);
			checkSQL($db_link, $query_inc_lf);

			//Insert Loan Insurance into INCOMES
			$loan_insurance = $loan_princp_approved / 100 * $_SESSION['fee_loaninsurance'];
			$sql_inc_ins = "INSERT INTO incomes (cust_id, loan_id, inctype_id, inc_amount, inc_date, inc_receipt, inc_created, user_id) VALUES ('$_SESSION[cust_id]', '$_SESSION[loan_id]', '10', '$loan_insurance', '$loan_dateout', '$loan_fee_receipt', '$timestamp', '$_SESSION[log_id]')";
			$query_inc_ins = mysqli_query($db_link, $sql_inc_ins);
			checkSQL($db_link, $query_inc_ins);

			//Insert Additional Loan Fee into INCOMES
			if($_SESSION['fee_xl1'] != 0){
				$loan_xtraFee1 = $_SESSION['fee_xl1'];
				$sql_inc_xtraFee1 = "INSERT INTO incomes (cust_id, loan_id, inctype_id, inc_amount, inc_date, inc_receipt, inc_created, user_id) VALUES ('$_SESSION[cust_id]', '$_SESSION[loan_id]', '11', '$loan_xtraFee1', '$loan_dateout', '$loan_fee_receipt', '$timestamp', '$_SESSION[log_id]')";
				$query_inc_xtraFee1 = mysqli_query($db_link, $sql_inc_xtraFee1);
				checkSQL($db_link, $query_inc_xtraFee1);
			}

			//Update loan information. Set loan to "Approved" and "Issued".
			$sql_issue = "UPDATE loans SET loanstatus_id = '$loan_status', loan_issued = '1', loan_dateout = '$loan_dateout', loan_principalapproved = '$loan_princp_approved', loan_fee = '$loan_fee', loan_fee_receipt = '$loan_fee_receipt', loan_insurance = '$loan_insurance', loan_insurance_receipt = '$loan_fee_receipt' WHERE loan_id = '$_SESSION[loan_id]'";
			$query_issue = mysqli_query($db_link, $sql_issue);
			checkSQL($db_link, $query_issue);
		}

		else {
			$sql_update = "UPDATE loans SET loanstatus_id = '$_POST[loan_status]' WHERE loan_id = $_SESSION[loan_id]";
			$query_update = mysqli_query($db_link, $sql_update);
			checkSQL($db_link, $query_update);
		}
		header('Location: loan.php?lid='.$_SESSION['loan_id']);
	}

	/** MAKE REPAYMENT Button **/
	if(isset($_POST['repay'])){

		//Sanitize User Input
		$loan_repay_amount = sanitize($db_link, $_POST['loan_repay_amount']);
		$loan_repay_receipt = sanitize($db_link, $_POST['loan_repay_receipt']);
		$loan_repay_date = sanitize($db_link, strtotime($_POST['loan_repay_date']));
		$loan_repay_sav = sanitize($db_link, $_POST['loan_repay_sav']);

		// If the paid amount exceeds the total outstanding balance,
		// the outstanding principal and interest are served
		// and the rest goes to savings.
		if ($loan_repay_amount > $_SESSION['balance']){
			$loan_repay_interest = $_SESSION['i_balance'];
			$loan_repay_principal = $_SESSION['p_balance'];
			$loan_repay_savings = $loan_repay_amount - $loan_repay_interest - $loan_repay_principal;
		}

		// If, however, the paid amount is smaller than the total outstanding balance...
		else {

			// Check if total interest has been paid off.
			if ($_SESSION['i_balance'] <= 0 AND $_SESSION['p_balance'] > 0){
					$loan_repay_interest = 0;
					$loan_repay_principal = $loan_repay_amount;
			}

			// Check if total principal has been paid off.
			elseif ($_SESSION['i_balance'] > 0 AND $_SESSION['p_balance'] <= 0){
					$loan_repay_interest = $loan_repay_amount;
					$loan_repay_principal = 0;
			}

			// Otherwise, if principal AND interest both show an open balance...
			elseif ($_SESSION['i_balance'] > 0 AND $_SESSION['p_balance'] > 0){

				// Check if the paid amount is less than the interest due.
				// In that case, everything goes to interest only.
				if ($loan_repay_amount < $_SESSION['interest_sum']){
					$loan_repay_interest = $loan_repay_amount;
					$loan_repay_principal = 0;
				}

				// If, however, the paid amount exceeds
				// the due interest PLUS the total outstanding balance,
				// the excess money is used on interest.
				elseif ($loan_repay_amount > ($_SESSION['interest_sum'] + $_SESSION['p_balance'])){
					$loan_repay_principal = $_SESSION['p_balance'];
					$loan_repay_interest = $loan_repay_amount - $loan_repay_principal;
				}

				//Otherwise, the paid amount is split between interest and principal.
				// This is probably the most common case!
				else {
					$loan_repay_interest = $_SESSION['interest_sum'];
					$loan_repay_principal = $loan_repay_amount - $loan_repay_interest;
				}
			}
		}

		// Check for smallest LTRANS_ID to determine whether an UPDATE or INSERT is needed
		$sql_ltransid = "SELECT MIN(ltrans_id) FROM ltrans WHERE loan_id = $_SESSION[loan_id] AND ltrans_date IS NULL AND ltrans_due IS NOT NULL";
		$query_ltransid = mysqli_query($db_link, $sql_ltransid);
		checkSQL($db_link, $query_ltransid);
		$ltransid_result = mysqli_fetch_assoc($query_ltransid);
		$ltransid = $ltransid_result['MIN(ltrans_id)'];

		if(!isset($ltransid)){
			$sql_insertrepay = "INSERT INTO ltrans (loan_id, ltrans_date, ltrans_principal, ltrans_interest, ltrans_receipt, ltrans_created, user_id) VALUES ($_SESSION[loan_id], $loan_repay_date, '$loan_repay_principal', '$loan_repay_interest', '$loan_repay_receipt', $timestamp, '$_SESSION[log_id]')";
			$query_insertrepay = mysqli_query($db_link, $sql_insertrepay);
			checkSQL($db_link, $query_insertrepay);

			// Get LTRANS_ID of latest entry
			$sql_ltransid = "SELECT MAX(ltrans_id) FROM ltrans WHERE loan_id = '$_SESSION[loan_id]' AND ltrans_receipt = '$loan_repay_receipt' AND ltrans_created = '$timestamp'";
			$query_ltransid = mysqli_query($db_link, $sql_ltransid);
			checkSQL($db_link, $query_ltransid);
			$ltransid_result = mysqli_fetch_row($query_ltransid);
			$ltransid = $ltransid_result[0];
		}
		else {
			$sql_updaterepay = "UPDATE ltrans SET ltrans_date = $loan_repay_date, ltrans_principal = '$loan_repay_principal', ltrans_interest = '$loan_repay_interest', ltrans_receipt = '$loan_repay_receipt', ltrans_created = '$timestamp', user_id = '$_SESSION[log_id]' WHERE ltrans_id = $ltransid";
			$query_updaterepay = mysqli_query($db_link, $sql_updaterepay);
			checkSQL($db_link, $query_updaterepay);
		}

		// If interest is paid, insert the amount into INCOMES
		if($loan_repay_interest > 0){
			$sql_incint = "INSERT INTO incomes (cust_id, inctype_id, ltrans_id, inc_amount, inc_date, inc_receipt, inc_created, user_id) VALUES ('$_SESSION[cust_id]', '4', '$ltransid', '$loan_repay_interest', '$loan_repay_date', '$loan_repay_receipt', $timestamp, '$_SESSION[log_id]')";
			$query_incint = mysqli_query($db_link, $sql_incint);
			checkSQL($db_link, $query_incint);
		}

		// If Payment is made from savings, withdraw the amount from there
		if ($loan_repay_sav == 1) {
			$loan_repay_amount_sav = $loan_repay_amount * (-1);

			$sql_insert = "INSERT INTO savings (cust_id, ltrans_id, sav_date, sav_amount, savtype_id, sav_receipt, sav_created, user_id) VALUES ($_SESSION[cust_id], $ltransid, $loan_repay_date, $loan_repay_amount_sav, 8, $loan_repay_receipt, $timestamp, $_SESSION[log_id])";
			$query_insert = mysqli_query($db_link, $sql_insert);

			// Update savings account balance
			updateSavingsBalance($db_link, $_SESSION['cust_id']);
		}

		//If amount paid exceeds the remaining balance for that loan, put the rest in SAVINGS.
		if(isset($loan_repay_savings)){
			$sql_restsav = "INSERT INTO savings (cust_id, ltrans_id, sav_date, sav_amount, savtype_id, sav_receipt, sav_created, user_id) VALUES ($_SESSION[cust_id], $ltransid, $loan_repay_date, $loan_repay_savings, '1', $loan_repay_receipt, $timestamp, '$_SESSION[log_id]')";
			$query_restsav = mysqli_query($db_link, $sql_restsav);
			checkSQL($db_link, $query_restsav);

			// Update savings account balance
			updateSavingsBalance($db_link, $_SESSION['cust_id']);
		}

		// Re-calculate interest payments
		if($_SESSION['set_intcalc'] == "modules/mod_inter_float.php") {
			$loan_balances = getLoanBalance($_SESSION['loan_id']);
			$loan_pBalance = $result_loan['loan_principalapproved'] - $loan_balances['ppaid'];
			updateInterFloat($db_link, $_SESSION['loan_id'], $loan_pBalance, $result_loan['loan_interest']);
		}

		// Re-load loan.php
		header('Location: loan.php?lid='.$_SESSION['loan_id']);
	}

	/** CHARGE DEFAULT FINE Button **/
	if(isset($_POST['fine'])){

		// Sanitize user input
		$fine_amount = sanitize($db_link, $_POST['fine_amount']);
		$fine_receipt = sanitize($db_link, $_POST['fine_receipt']);
		$fine_date = strtotime(sanitize($db_link, $_POST['fine_date']));
		if(isset($_POST['fine_sav'])) $fine_sav = sanitize($db_link, $_POST['fine_sav']);
		else $fine_sav = 0;

		// Get LTRANS_ID for chargable transaction
		$sql_ltrans = "SELECT MIN(ltrans_id) FROM ltrans WHERE ltrans.loan_id = '$_SESSION[loan_id]' AND ltrans_due < '$timestamp' AND ltrans_fined = '0' AND ltrans_date IS NULL";
		$query_ltrans = mysqli_query($db_link, $sql_ltrans);
		checkSQL($db_link, $query_ltrans);
		$ltrans = mysqli_fetch_row($query_ltrans);

		// Flag loan transaction as 'fined'
		$sql_ltrans_fined = "UPDATE ltrans SET ltrans_fined = '1', ltrans_created = '$timestamp', user_id = '$_SESSION[log_id]' WHERE ltrans_id = '$ltrans[0]'";
		$query_ltrans_fined = mysqli_query($db_link, $sql_ltrans_fined);
		checkSQL($db_link, $query_ltrans_fined);

		// Deduct fine from savings account if applicable
		if($fine_sav == 1){
			$fine_amount_sav = $fine_amount * (-1);

			$sql_fine_sav = "INSERT INTO savings (cust_id, ltrans_id, sav_date, sav_amount, savtype_id, sav_receipt, sav_created, user_id) VALUES ('$_SESSION[cust_id]', '$ltrans[0]', '$fine_date', '$fine_amount_sav', 6, '$fine_receipt', $timestamp, '$_SESSION[log_id]')";
			$query_fine_sav = mysqli_query($db_link, $sql_fine_sav);
			checkSQL($db_link, $query_fine_sav);

			// Update savings account balance
			updateSavingsBalance($db_link, $_SESSION['cust_id']);

			// Get SAV_ID for the latest entry
			$sql_savid = "SELECT MAX(sav_id) FROM savings WHERE ltrans_id = '$ltrans[0]' AND sav_receipt = '$fine_receipt' AND sav_created = '$timestamp'";
			$query_savid = mysqli_query($db_link, $sql_savid);
			checkSQL($db_link, $query_savid);
			$sav_id = mysqli_fetch_row($query_savid);
		}
		else $sav_id[0] = NULL;

		// Insert fine as income in INCOMES
		$sql_fine_inc = "INSERT INTO incomes (cust_id, ltrans_id, sav_id, inctype_id, inc_amount, inc_date, inc_receipt, inc_created, user_id) VALUES ('$_SESSION[cust_id]', '$ltrans[0]', '$sav_id[0]', '5', '$fine_amount', '$fine_date', '$fine_receipt', $timestamp, '$$_SESSION[log_id]')";
		$query_fine_inc = mysqli_query($db_link, $sql_fine_inc);
		checkSQL($db_link, $query_fine_inc);

		header('Location: loan.php?lid='.$_SESSION[loan_id]);
	}

	// Select Instalments from LTRANS
	$sql_duedates = "SELECT * FROM ltrans LEFT JOIN user ON ltrans.user_id = user.user_id WHERE loan_id = $_SESSION[loan_id] ORDER BY ltrans_id";
	$query_duedates = mysqli_query($db_link, $sql_duedates);
	checkSQL($db_link, $query_duedates);

	// Select Guarantors from CUSTOMER
	$sql_guarant = "SELECT cust_id, cust_no, cust_name FROM customer";
	$query_guarant = mysqli_query($db_link, $sql_guarant);
	checkSQL($db_link, $query_guarant);
	$guarantors = array();
	while ($row_guarant = mysqli_fetch_assoc($query_guarant)) $guarantors[] = $row_guarant;

	// Select Securities from SECURITIES and get file paths for securities
	$sql_secur = "SELECT * FROM securities WHERE loan_id = $_SESSION[loan_id]";
	$query_secur = mysqli_query($db_link, $sql_secur);
	checkSQL($db_link, $query_secur);
	$securities = array();
	while ($row_secur = mysqli_fetch_assoc($query_secur)) $securities[] = $row_secur;
	foreach ($securities as $s){
		if ($s['sec_no'] == 1) $sec_path1 = $s['sec_path'];
		elseif ($s['sec_no'] == 2) $sec_path2 = $s['sec_path'];
	}

	//Prepare array data export
	$ltrans_exp_date = date("Y-m-d",time());
	$_SESSION['ltrans_export'] = array();
	$_SESSION['ltrans_exp_title'] = $_SESSION['cust_id'].'_loan_'.$ltrans_exp_date;
?>

<html>
	<?PHP includeHead('Loan Details',0) ?>
		<script type="text/javascript">
			function firstIssue(form){
				status = form.loan_status.value;
				issued = form.loan_issued.value;

				if (status == 2 && issued == 0) {

					fail = validateDate(form.loan_dateout.value)
					if (fail != "") {
						alert(fail);
						return false;
					}

					loan_fee_receipt = prompt('Please enter Receipt No. for Loan Fee:')
					if (loan_fee_receipt == "" || loan_fee_receipt == null) {
						alert("You have not specified the Receipt No. The Loan's Status remains unchanged.");
						return false;
					}
					else {
						document.getElementById("loan_fee_receipt").value = loan_fee_receipt;
						return true;
					}
				}
				else return true;
			}

			function validate(form){
				fail = validateDate(form.loan_repay_date.value)
				fail += validateAmount(form.loan_repay_amount.value)
				fail += validateReceipt(form.loan_repay_receipt.value)
				if (form.loan_repay_sav.checked){
					fail += validateOverdraft(form.loan_repay_amount.value, <?PHP echo $sav_balance; ?>, 0, <?PHP echo $_SESSION['set_msb']; ?>)
				}
				if (fail == "") return true
				else {alert(fail); return false}
			}

			function validateFine(form){
				fail = validateDate(form.fine_date.value)
				fail += validateAmount(form.fine_amount.value)
				if (form.fine_sav.checked){
					fail += validateOverdraft(form.fine_amount.value, <?PHP echo $sav_balance; ?>, 0, <?PHP echo $_SESSION['set_msb']; ?>)
				}
				fail += validateReceipt(form.fine_receipt.value)
				if (fail == "") return true
				else {alert(fail); return false}
			}
		</script>
		<script src="functions_validate.js"></script>
		<script src="function_randCheck.js"></script>
	</head>

	<body>
		<!-- MENU -->
		<?PHP includeMenu(5); ?>
		<div id="menu_main">
			
			<a href="rep_incomes.php" id="item_selected">Income Report</a>
			<a href="rep_expenses.php">Expense Report</a>
			<a href="rep_loans.php">Loans Report</a>
			<a href="rep_capital.php">Capital Report</a>
			<a href="rep_monthly.php">Monthly Report</a>
			<a href="rep_annual.php">Annual Report</a>
			<a href="loans_act_State.php">Loan Portfolio</a>
			
		</div>


		<!-- RIGHT SIDE: Payment Transactions -->
		<div class="content_center">

		<p class="heading_narrow">Loan No. <?PHP echo $result_loan['loan_no'] ?></p>
			<table id="tb_table">
				<tr>
					<form class="export" action="ltrans_export.php" method="post">
						<th class="title" colspan="10">Cusomer Loan Portfolio
						<!-- Export Button -->
						<input type="submit" name="export_rep" value="Export" />
						</th>
					</form>
				</tr>
				<tr> 
					<th>Date due</th>
					<th>Princ. due</th>
					<th>Inter. due</th>
					<th>Princ/Inter</th>
					<th>Loan Portfolio</th>
				
				</tr>
				<?PHP
				$p_due = 0;
				$p_paid = 0;
				$i_due = 0;
				$i_paid = 0;
				$loan_default = 0;
				$int_sum_set = 0;
				while ($row_duedates = mysqli_fetch_assoc($query_duedates)){
					echo '<tr>';
					if ($row_duedates['ltrans_due'] === NULL) echo '<td></td>';
						elseif ($row_duedates['ltrans_due'] < $timestamp AND $row_duedates['ltrans_date'] === NULL AND $row_duedates['ltrans_fined'] == 0) echo '<td class="warn">'.date("d.m.Y",$row_duedates['ltrans_due']).'</td>';
						
					
						
						if ($row_duedates['ltrans_due'] === NULL) echo '<td></td>';
						elseif ($row_duedates['ltrans_due'] < $timestamp AND $row_duedates['ltrans_date'] === NULL AND $row_duedates['ltrans_fined'] == 0) echo '<td class="warn">'.number_format($row_duedates['ltrans_principaldue']).'</td>';
						
					
					if ($row_duedates['ltrans_due'] === NULL) echo '<td></td>';
						elseif ($row_duedates['ltrans_due'] < $timestamp AND $row_duedates['ltrans_date'] === NULL AND $row_duedates['ltrans_fined'] == 0) echo '<td class="warn">'.number_format($row_duedates['ltrans_interestdue']).'</td>';
						
						
						if ($row_duedates['ltrans_due'] === NULL) echo '<td></td>';
						elseif ($row_duedates['ltrans_due'] < $timestamp AND $row_duedates['ltrans_date'] === NULL AND $row_duedates['ltrans_fined'] == 0) echo '<td class="warn">'.number_format($row_duedates['ltrans_interestdue'] + $row_duedates['ltrans_principaldue']).'</td>';
						
						
						if ($row_duedates['ltrans_due'] === NULL) echo '<td></td>';
						elseif ($row_duedates['ltrans_due'] < $timestamp AND $row_duedates['ltrans_date'] === NULL AND $row_duedates['ltrans_fined'] == 0) echo '<td class="warn">'.number_format($row_duedates['ltrans_interestdue'] + $row_duedates['ltrans_principaldue']+$l_due).'</td>';
						
			

					$p_due = $p_due + $row_duedates['ltrans_principaldue'];
					
					$i_due = $i_due + $row_duedates['ltrans_interestdue'];
					
					$l_due = $l_due + $row_duedates['ltrans_principaldue']+ $row_duedates['ltrans_principaldue'];
					
					
					
				
					
					if ($row_duedates['ltrans_date'] === NULL && $int_sum_set == 0){
						$_SESSION['interest_sum'] = $row_duedates['ltrans_interestdue'];
						$int_sum_set = 1;
					}
					elseif ($int_sum_set == 0) $_SESSION['interest_sum'] = 0;
					if ($row_duedates['ltrans_date'] == NULL &&
							$row_duedates['ltrans_due'] != NULL &&
							$row_duedates['ltrans_due'] < $timestamp &&
							$row_duedates['ltrans_fined'] == 0)
						$loan_default++;

					if ($row_duedates['ltrans_fined'] == 0) $exp_fined='No';
						else $exp_fined='Yes';

					//Prepare data for export to Excel file
					array_push($_SESSION['ltrans_export'], array("Date due" => date("d.m.Y",$row_duedates['ltrans_due']), "Date paid" => date("d.m.Y",$row_duedates['ltrans_date']), "Principial due" => $row_duedates['ltrans_principaldue'], "Principal paid" => $row_duedates['ltrans_principal'], "Interest due" => $row_duedates['ltrans_interestdue'], "Interest paid" => $row_duedates['ltrans_interest'], "Receipt" => $row_duedates['ltrans_receipt'], "Fined" => $exp_fined));
				}

				//Pass relevant data to SESSION
				$_SESSION['p_due'] = $p_due;
				$_SESSION['p_paid'] = $p_paid;
				$_SESSION['i_due'] = $i_due;
				$_SESSION['i_paid'] = $i_paid;
				$_SESSION['p_balance'] = $p_due - $p_paid;
				$_SESSION['i_balance'] = $i_due - $i_paid;
				$_SESSION['balance'] = $p_due - $p_paid + $i_due - $i_paid;
				$_SESSION['lportfolio'] = $i_due + $p_due + $row_duedates;
				
				if (isset($_SESSION['interest_sum']) AND $_SESSION['interest_sum'] == 0) $_SESSION['interest_sum'] = $_SESSION['i_balance'];
				?>

				<tr class="balance">
					<td>Total:</td>
				
					<td><?PHP echo number_format($_SESSION['loan_principal']); ?></td>
					
					<td><?PHP echo number_format($i_due); ?></td>
					
					<td colspan="4"></td>
				</tr>
				<tr>
					<td>Remaining:</td>
					
					<td><?PHP echo number_format($_SESSION['loan_principal'] - $p_paid); ?></td>
					
					<td><?PHP echo number_format($i_due - $i_paid); ?></td>
					<td colspan="5"></td>
				</tr>
			</table>
		
		</div>
	</body>
</html>
