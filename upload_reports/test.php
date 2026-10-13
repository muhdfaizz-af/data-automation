<div class="grid-2">
                    <div class="form-group">
                        <label class="form-label" for="compositeSku">Composite SKU <span class="required">*</span></label>
                        <?php if ($isEditing): ?>
                            <input class="form-control readonly-control" id="compositeSku" type="text" value="<?= compositeEscape($originalCode) ?>" readonly aria-describedby="compositeSkuHint">
                            <input type="hidden" name="item_code" value="<?= compositeEscape($originalCode) ?>">
                            <span class="form-hint" id="compositeSkuHint">Composite SKU cannot be changed.</span>
                        <?php else: ?>
                            <input class="form-control uppercase-input" id="compositeSku" name="item_code" maxlength="50" value="<?= compositeEscape($formCode) ?>" placeholder="E.g. DRINKS4" autocomplete="off"required>
                        <?php endif; ?>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="compositeName">Composite name <span class="required">*</span></label>
                        <input class="form-control uppercase-input" id="compositeName" name="item_description" maxlength="255" value="<?= compositeEscape($formName) ?>" placeholder="E.g. COMBO DRINKS x4" autocomplete="off" required>
                    </div>
                </div>