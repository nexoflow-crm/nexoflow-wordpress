PLUGIN_DIR := nexoflow
ZIP_NAME := nexoflow.zip

.PHONY: zip test

zip:
	rm -f $(ZIP_NAME)
	zip -r $(ZIP_NAME) $(PLUGIN_DIR) \
		-x "$(PLUGIN_DIR)/.git/*" \
		-x "$(PLUGIN_DIR)/.github/*" \
		-x "$(PLUGIN_DIR)/tests/*" \
		-x "$(PLUGIN_DIR)/*brief*" \
		-x "*.DS_Store"

test:
	php tests/run.php
