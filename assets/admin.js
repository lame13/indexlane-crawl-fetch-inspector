( function () {
	'use strict';

	const config = window.ILCFI_SCAN;
	const form = document.getElementById( 'ilcfi-scan-form' );
	if ( ! config || ! form ) {
		return;
	}

	const runButton = document.getElementById( 'ilcfi-run' );
	const progressSection = document.getElementById( 'ilcfi-progress' );
	const progressBar = document.getElementById( 'ilcfi-progress-bar' );
	const progressText = document.getElementById( 'ilcfi-progress-text' );
	const messages = document.getElementById( 'ilcfi-messages' );
	const resultsSection = document.getElementById( 'ilcfi-results-section' );
	const results = document.getElementById( 'ilcfi-results' );
	const exportForm = document.getElementById( 'ilcfi-export-form' );
	const jobToken = document.getElementById( 'ilcfi-job-token' );
	const sitemapManifest = document.getElementById( 'ilcfi-sitemap-manifest' );
	let active = false;

	function selectedMode() {
		const input = form.querySelector( 'input[name="ilcfi_mode"]:checked' );
		return input ? input.value : 'manual';
	}

	function updateMode() {
		const mode = selectedMode();
		form.querySelectorAll( '[data-ilcfi-modes]' ).forEach( function ( row ) {
			row.hidden = ! row.dataset.ilcfiModes.split( ' ' ).includes( mode );
		} );
	}

	function setProgress( progress ) {
		progressSection.hidden = false;
		progressBar.value = Number( progress.percent || 0 );
		progressText.textContent = progress.text || '';
	}

	function showMessages( entries, isError ) {
		if ( ! Array.isArray( entries ) ) {
			entries = entries ? [ entries ] : [];
		}

		entries.forEach( function ( entry ) {
			const notice = document.createElement( 'div' );
			notice.className = 'notice ' + ( isError ? 'notice-error' : 'notice-warning' ) + ' inline';
			const paragraph = document.createElement( 'p' );
			paragraph.textContent = entry;
			notice.appendChild( paragraph );
			messages.appendChild( notice );
		} );
	}

	async function post( body ) {
		const response = await fetch( config.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: body,
		} );
		const payload = await response.json();
		if ( ! response.ok || ! payload.success ) {
			const message = payload && payload.data && payload.data.message ? payload.data.message : config.strings.failed;
			const error = new Error( message );
			error.status = response.status;
			throw error;
		}

		return payload.data;
	}

	async function startScan() {
		if ( active ) {
			return;
		}

		active = true;
		runButton.disabled = true;
		messages.replaceChildren();
		results.replaceChildren();
		sitemapManifest.replaceChildren();
		resultsSection.hidden = true;
		exportForm.hidden = true;
		setProgress( { percent: 1, text: config.strings.starting } );

		try {
			const body = new FormData( form );
			body.append( 'action', 'ilcfi_start_scan' );
			body.append( 'nonce', config.nonce );
			const data = await post( body );
			storageSet( data.token );
			jobToken.value = data.token;
			showMessages( data.messages, false );
			setProgress( data.progress );
			await advance( data.token );
		} catch ( error ) {
			fail( error );
		}
	}

	async function advance( token ) {
		try {
			const body = new FormData();
			body.append( 'action', 'ilcfi_scan_batch' );
			body.append( 'nonce', config.nonce );
			body.append( 'token', token );
			const data = await post( body );
			setProgress( data.progress );
			renderSitemapManifest( data.sitemap );

			if ( Array.isArray( data.rows ) && data.rows.length ) {
				resultsSection.hidden = false;
				data.rows.forEach( renderRow );
			}

			if ( data.complete ) {
				if ( Array.isArray( data.allRows ) ) {
					results.replaceChildren();
					data.allRows.forEach( renderRow );
				}

				resultsSection.hidden = false;
				if ( ! results.children.length ) {
					showMessages( config.strings.noResults, true );
				}
				progressText.textContent = config.strings.complete;
				progressBar.value = 100;
				exportForm.hidden = ! results.children.length;
				jobToken.value = token;
				storageRemove();
				active = false;
				runButton.disabled = false;
				return;
			}

			window.setTimeout( function () {
				advance( token );
			}, 100 );
		} catch ( error ) {
			fail( error );
		}
	}

	function fail( error ) {
		active = false;
		runButton.disabled = false;
		if ( error.status === 404 ) {
			storageRemove();
		}
		showMessages( error.message || config.strings.failed, true );
		progressText.textContent = config.strings.failed;
	}

	function renderSitemapManifest( manifest ) {
		if ( ! manifest || ! manifest.checked ) {
			sitemapManifest.replaceChildren();
			return;
		}

		const text = [
			'Sitemap evidence: ' + manifest.completeness,
			manifest.filesChecked + ' files checked',
			manifest.urlsObserved + ' URLs observed',
			manifest.filesFailed + ' failed files',
			manifest.truncated + ' truncated files',
			manifest.omittedFiles + ' omitted files',
			manifest.omittedUrls + ' omitted URLs',
		].join( ' · ' );
		const paragraph = document.createElement( 'p' );
		paragraph.className = 'ilcfi-manifest';
		paragraph.textContent = text;
		sitemapManifest.replaceChildren( paragraph );
	}

	function renderRow( row ) {
		const article = document.createElement( 'article' );
		article.className = 'ilcfi-report-card';
		const heading = document.createElement( 'div' );
		heading.className = 'ilcfi-report-heading';
		const title = document.createElement( 'h3' );
		title.textContent = row.input_url || 'Unknown URL';
		const badge = document.createElement( 'span' );
		badge.className = 'ilcfi-result ilcfi-result-' + slug( row.result );
		badge.textContent = row.result || 'Unknown';
		heading.append( title, badge );
		article.appendChild( heading );

		const grid = document.createElement( 'div' );
		grid.className = 'ilcfi-report-grid';
		grid.append(
			section( 'HTTP status and redirects', [
				[ 'HTTP status', row.http_status ],
				[ 'Redirect chain', row.redirect_chain ],
				[ 'Final URL', row.final_url ],
				[ 'Response time', row.response_time ],
			] ),
			section( 'Canonical and robots directives', [
				[ 'Canonical', row.canonical_url || 'None found' ],
				[ 'Robots directives', row.robots_directives ],
				[ 'Effective robots.txt for Googlebot', row.robots_txt ],
			] ),
			section( 'Sitemap membership', [
				[ 'Membership', row.sitemap_membership ],
			] ),
			section( 'JSON-LD', [
				[ 'Blocks', row.json_ld_count ],
				[ 'Validity', row.json_ld_validity ],
				[ '@type inventory', row.json_ld_types ],
				[ 'Duplicate @id values', row.duplicate_json_ld_ids ],
			] ),
			section( 'Old / staging-domain evidence', [
				[ 'Summary', row.old_domain_evidence ],
			] ),
			section( 'Evidence completeness', [
				[ 'State', row.evidence_completeness ],
				[ 'Why', row.completeness_detail || 'All requested evidence completed.' ],
				[ 'Finding detail', row.detail || 'No review finding from the inspected evidence.' ],
			] )
		);
		article.appendChild( grid );

		if ( Array.isArray( row.residue_evidence ) && row.residue_evidence.length ) {
			const details = document.createElement( 'details' );
			const summary = document.createElement( 'summary' );
			summary.textContent = config.strings.evidence;
			details.appendChild( summary );
			const list = document.createElement( 'ul' );
			row.residue_evidence.forEach( function ( evidence ) {
				const item = document.createElement( 'li' );
				item.textContent = evidence.reason + ' · ' + evidence.context + ' · ' + evidence.matched_value + ' · ' + evidence.snippet;
				list.appendChild( item );
			} );
			details.appendChild( list );
			article.appendChild( details );
		}

		results.appendChild( article );
	}

	function section( heading, rows ) {
		const wrapper = document.createElement( 'section' );
		const title = document.createElement( 'h4' );
		title.textContent = heading;
		const list = document.createElement( 'dl' );
		rows.forEach( function ( row ) {
			const term = document.createElement( 'dt' );
			term.textContent = row[ 0 ];
			const value = document.createElement( 'dd' );
			value.textContent = row[ 1 ] || '—';
			list.append( term, value );
		} );
		wrapper.append( title, list );
		return wrapper;
	}

	function slug( value ) {
		return String( value || 'unknown' ).toLowerCase().replace( /[^a-z0-9]+/g, '-' ).replace( /^-|-$/g, '' );
	}

	function storageSet( token ) {
		try {
			window.localStorage.setItem( config.resumeKey, token );
		} catch ( error ) {
			// The active page can still complete when persistent browser storage is disabled.
		}
	}

	function storageGet() {
		try {
			return window.localStorage.getItem( config.resumeKey );
		} catch ( error ) {
			return null;
		}
	}

	function storageRemove() {
		try {
			window.localStorage.removeItem( config.resumeKey );
		} catch ( error ) {
			// Nothing else is required when persistent browser storage is disabled.
		}
	}

	form.addEventListener( 'change', function ( event ) {
		if ( event.target.name === 'ilcfi_mode' ) {
			updateMode();
		}
	} );
	form.addEventListener( 'submit', function ( event ) {
		event.preventDefault();
		startScan();
	} );

	updateMode();
	const savedToken = storageGet();
	if ( savedToken ) {
		active = true;
		runButton.disabled = true;
		jobToken.value = savedToken;
		setProgress( { percent: 1, text: config.strings.resuming } );
		advance( savedToken );
	}
}() );
