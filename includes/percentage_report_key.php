<div class="row mt-3 align-items-center percentage-result">
    <div class="col-8">
        <span class="fw-bold d-block mb-1 small">Division Key:</span>
        <table class="table table-bordered table-sm text-center mb-0" style="font-size:0.7rem">
            <thead><tr><th>Total Percentage</th><th>Division</th></tr></thead>
            <tbody>
                <tr><td>80% to 100%</td><td>Distinction</td></tr>
                <tr><td>60% to below 80%</td><td>First Division</td></tr>
                <tr><td>45% to below 60%</td><td>Second Division</td></tr>
                <tr><td>35% to below 45%</td><td>Third Division</td></tr>
                <tr><td>Below 35% or a failed required component</td><td>Failed</td></tr>
                <tr><td>Missing required marks</td><td>Incomplete</td></tr>
            </tbody>
        </table>
    </div>
    <div class="col-4 border p-2 bg-light text-center rounded">
        <div>Final Total Percentage: <strong><?= $final_gpa_text==='Incomplete'?'—':sanitize($final_gpa_text) ?></strong></div>
        <div>Division: <strong><?= sanitize($final_grade) ?></strong></div>
    </div>
</div>
