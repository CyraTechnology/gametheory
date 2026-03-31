<div class="row">

<div class="col-md-3">
<div class="card stat-card">
Wallet Balance
<div class="stat-number">₹8000</div>
</div>
</div>

<div class="col-md-3">
<div class="card stat-card">
Total Spent
<div class="stat-number">₹150000</div>
</div>
</div>

<div class="col-md-3">
<div class="card stat-card">
Price Drop Income
<div class="stat-number">₹7000</div>
</div>
</div>

<div class="col-md-3">
<div class="card stat-card">
Orders
<div class="stat-number">12</div>
</div>
</div>

</div>

<br>

<div class="card p-3">

<h5>Market Impact Analytics</h5>

<canvas id="userChart"></canvas>

</div>

<script>

new Chart(document.getElementById('userChart'),{

type:'doughnut',

data:{
labels:['Purchases','Refunds','Market Impact'],
datasets:[{
data:[70,20,10]
}]
}

});

</script>