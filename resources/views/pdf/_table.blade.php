<?php
if (!empty($data)): ?>
<div class="table-container">
    <h2><?php echo htmlspecialchars($title); ?></h2>
    <table>
        <thead>
            <tr>
                <?php foreach ($headers as $header): ?>
                    <th><?php echo htmlspecialchars($header); ?></th>
                <?php endforeach; ?>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($data as $row): ?>
                <?php
                    $isSummary = (isset($row[0]) && str_contains(strtolower((string)$row[0]), 'sum'));
                ?>
                <tr<?php if ($isSummary) echo ' class="summary-row"'; ?>>
                    <?php foreach ($row as $cell): ?>
                        <td><?php echo htmlspecialchars((string)$cell); ?></td>
                    <?php endforeach; ?>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>