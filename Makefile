PLUGIN_DIR=plugin/carsystem-regional-sync
PLUGIN_SLUG=carsystem-regional-sync
DIST_DIR=dist

.PHONY: init lint package clean

init:
	@mkdir -p $(DIST_DIR)
	@echo "Init complete"

lint:
	@find $(PLUGIN_DIR) -name "*.php" -print0 | xargs -0 -n1 php -l

package:
	@mkdir -p $(DIST_DIR)
	@cd plugin && zip -r ../$(DIST_DIR)/$(PLUGIN_SLUG).zip $(PLUGIN_SLUG) >/dev/null
	@echo "Created $(DIST_DIR)/$(PLUGIN_SLUG).zip"

clean:
	@rm -rf $(DIST_DIR)
