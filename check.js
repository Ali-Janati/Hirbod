
        (function() {
            'use strict';

            const API_BASE = window.location.hostname === 'localhost' ? '/hirbod/api' : 'https://hrbsabt.ir/api';
            const TOKEN_STORAGE_KEY = 'hirbad-salt-token';

            let currentToken = null;
            let currentRole  = null;
            let currentUserName = null;

            let stock    = {};
            let forecast = {};
            let orders   = [];
            let products = [];
            let tokens   = [];

            // ============================================================
            // 1b. لایه‌ی API — تفکیک GET و POST
            // ============================================================
            async function apiGet(endpoint) {
                // توکن رو به URL اضافه کن (برای PHPهایی که از $_GET استفاده می‌کنن)
                let url = `${API_BASE}/${endpoint}`;
                if (!endpoint.includes('?')) {
                    url += '?';
                } else {
                    url += '&';
                }
                url += `token=${encodeURIComponent(currentToken)}`;

                const res = await fetch(url, { method: 'GET' });
                const text = await res.text();
                try {
                    const data = JSON.parse(text);
                    if (!data.success) throw new Error(data.message || 'خطای سرور');
                    return data.data;
                } catch (e) {
                    throw new Error(`سرور JSON برنگردوند. متن پاسخ: ${text.substring(0, 150)}`);
                }
            }

            async function apiPost(endpoint, body = {}) {
                // توکن رو هم به URL اضافه کن (برای PHPهایی که از $_GET می‌خونن)
                let url = `${API_BASE}/${endpoint}`;
                if (!endpoint.includes('?')) {
                    url += '?';
                } else {
                    url += '&';
                }
                url += `token=${encodeURIComponent(currentToken)}`;

                const res = await fetch(url, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(body), // توکن رو توی بدنه نمی‌فرستیم (قبلاً در URL هست)
                });
                const text = await res.text();
                try {
                    const data = JSON.parse(text);
                    if (!data.success) throw new Error(data.message || 'خطای سرور');
                    return data.data;
                } catch (e) {
                    throw new Error(`سرور JSON برنگردوند. متن پاسخ: ${text.substring(0, 150)}`);
                }
            }

            // ============================================================
            // 2. توابع کمکی
            // ============================================================
            function getToday() {
                return new Date().toISOString().split('T')[0];
            }

            function addDays(dateStr, days) {
                const d = new Date(dateStr);
                d.setDate(d.getDate() + days);
                return d.toISOString().split('T')[0];
            }

            function showMsg(el, text, type) {
                el.innerHTML = `<div class="msg msg-${type}">${text}</div>`;
            }

            function clearMsg(el) {
                el.innerHTML = '';
            }

            // لودینگ دکمه‌ها
            function setBtnLoading(btn, loading, originalText) {
                if (loading) {
                    btn.disabled = true;
                    btn.dataset.originalText = btn.textContent;
                    btn.innerHTML = originalText + ' <span class="loading-spinner"></span>';
                } else {
                    btn.disabled = false;
                    btn.textContent = btn.dataset.originalText || originalText;
                }
            }

            function formatPrice(n) {
                return Number(n || 0).toLocaleString('fa-IR');
            }

            function activeProductNames() {
                return products.filter(p => Number(p.is_active) === 1).map(p => p.name);
            }

            function productByName(name) {
                return products.find(p => p.name === name);
            }

            const JALALI_MONTHS = [
                'فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور',
                'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند'
            ];

            function toPersianDigits(input) {
                const fa = ['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹'];
                return String(input).replace(/[0-9]/g, d => fa[d]);
            }

            function gregorianToJalali(gy, gm, gd) {
                const g_d_m = [0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334];
                let jy = (gy <= 1600) ? 0 : 979;
                gy -= (gy <= 1600) ? 621 : 1600;
                const gy2 = (gm > 2) ? (gy + 1) : gy;
                let days = (365 * gy) + Math.floor((gy2 + 3) / 4) - Math.floor((gy2 + 99) / 100) +
                    Math.floor((gy2 + 399) / 400) - 80 + gd + g_d_m[gm - 1];
                jy += 33 * Math.floor(days / 12053);
                days %= 12053;
                jy += 4 * Math.floor(days / 1461);
                days %= 1461;
                if (days > 365) {
                    jy += Math.floor((days - 1) / 365);
                    days = (days - 1) % 365;
                }
                let jm, jd;
                if (days < 186) {
                    jm = 1 + Math.floor(days / 31);
                    jd = 1 + (days % 31);
                } else {
                    jm = 7 + Math.floor((days - 186) / 30);
                    jd = 1 + ((days - 186) % 30);
                }
                return [jy, jm, jd];
            }

            function toJalaliString(isoDate) {
                if (!isoDate) return '';
                const [gy, gm, gd] = isoDate.split('-').map(Number);
                const [jy, jm, jd] = gregorianToJalali(gy, gm, gd);
                const jmStr = String(jm).padStart(2, '0');
                const jdStr = String(jd).padStart(2, '0');
                return toPersianDigits(`${jy}/${jmStr}/${jdStr}`);
            }

            function toJalaliLongString(isoDate) {
                if (!isoDate) return '';
                const [gy, gm, gd] = isoDate.split('-').map(Number);
                const [jy, jm, jd] = gregorianToJalali(gy, gm, gd);
                return `${toPersianDigits(jd)} ${JALALI_MONTHS[jm - 1]} ${toPersianDigits(jy)}`;
            }

            // ============================================================
            // 2b. بارگذاری محصولات (با GET)
            // ============================================================
            async function loadProducts(includeInactive) {
                const data = await apiGet('list_products.php' + (includeInactive ? '?all=1' : ''));
                products = data;
                return products;
            }

            function fillProductSelects() {
                const names = activeProductNames();
                const orderSel = document.getElementById('orderType');
                const stockSel = document.getElementById('stockType');
                const orderOpts = names.map(n => {
                    const p = products.find(x => x.name === n);
                    const unitLabel = p ? (p.unit === 'بسته' ? `📦 ${p.weight_per_package} کیلوگرم/بسته` : '⚖️ کیلوگرم') : '';
                    return `<option value="${n}">${n} (${unitLabel})</option>`;
                }).join('');
                const optsHtml = names.map(n => `<option value="${n}">${n}</option>`).join('');
                if (orderSel) { orderSel.innerHTML = orderOpts; }
                if (stockSel) { stockSel.innerHTML = optsHtml; }
                updateOrderPricePreview();
            }

            function updateOrderPricePreview() {
                const sel = document.getElementById('orderType');
                const pricePreview = document.getElementById('orderPricePreview');
                if (!sel || !pricePreview) return;
                const p = productByName(sel.value);
                pricePreview.textContent = p ? `💰 قیمت واحد: ${formatPrice(p.price)} تومان` : '';
                updateOrderTotalPreview();
            }

            function updateOrderTotalPreview() {
                const sel = document.getElementById('orderType');
                const qtyInput = document.getElementById('orderQty');
                const totalPreview = document.getElementById('orderTotalPreview');
                if (!sel || !qtyInput || !totalPreview) return;
                const p = productByName(sel.value);
                const qty = parseInt(qtyInput.value) || 0;
                totalPreview.textContent = p ? `جمع کل: ${formatPrice(p.price * qty)} تومان` : '';
            }

            // ============================================================
            // 3. مقداردهی اولیه پیش‌بینی
            // ============================================================
            function initForecast() {
                const today = getToday();
                const names = activeProductNames();
                if (Object.keys(forecast).length === 0) {
                    for (let i = 0; i < 20; i++) {
                        const date = addDays(today, i + 1);
                        forecast[date] = {};
                        names.forEach(type => {
                            forecast[date][type] = 0;
                        });
                    }
                }
            }

            // ============================================================
            // 4. نمایش تولید
            // ============================================================
            function updateAdminStockDisplay() {
                const display = document.getElementById('adminStockDisplay');
                if (display) {
                    let html = '';
                    activeProductNames().forEach(type => {
                        const s = stock[type] || {};
                        const kg = s.quantity_kg || 0;
                        const pkg = s.quantity_pkg || 0;
                        const color = (kg > 0 || pkg > 0) ? 'stock-available' : 'stock-zero';
                        html += `<span class="${color}">${type}: ${kg} کیلوگرم + ${pkg} بسته</span> `;
                    });
                    display.innerHTML = html || '<span class="stock-zero">همه ۰</span>';
                }
            }

            // ============================================================
            // 5. رندر جدول پیش‌بینی
            // ============================================================
            function renderForecastTable() {
                const names = activeProductNames();
                const headRow = document.getElementById('forecastHeadRow');
                headRow.innerHTML = '<th class="date-col-th">تاریخ</th>' +
                    names.map(n => `<th>${n}</th>`).join('');

                if (Object.keys(forecast).length === 0) {
                    initForecast();
                }
                const tbody = document.getElementById('forecastBody');
                let html = '';
                const dates = Object.keys(forecast).sort();
                const isViewer = currentRole === 'viewer';
                dates.forEach(date => {
                    html += `<tr>`;
                    html += `<td class="date-col">${toJalaliString(date)}</td>`;
                    names.forEach(type => {
                        const val = forecast[date]?.[type] ?? 0;
                        html += `<td>
                                    <input type="number" min="0" class="forecast-input"
                                        data-date="${date}" data-type="${type}"
                                        value="${val}" ${isViewer ? 'readonly' : ''} />
                                </td>`;
                    });
                    html += `</tr>`;
                });
                tbody.innerHTML = html;
            }

            // ============================================================
            // 6. ذخیره تولید آینده (POST)
            // ============================================================
            async function saveForecast() {
                if (currentRole === 'viewer') return;
                const msg = document.getElementById('forecastMsg');
                const btn = document.getElementById('saveForecastBtn');

                const inputs = document.querySelectorAll('.forecast-input');
                inputs.forEach(input => {
                    const date = input.dataset.date;
                    const type = input.dataset.type;
                    const val  = parseInt(input.value) || 0;
                    if (!forecast[date]) forecast[date] = {};
                    forecast[date][type] = val;
                });

                btn.disabled    = true;
                btn.textContent = '⏳ در حال ذخیره...';
                clearMsg(msg);

                try {
                    const result = await apiPost('save_forecast.php', { forecast });
                    showMsg(msg, `✅ تولید آینده ذخیره شد! (${result.saved_rows} ردیف)`, 'success');
                    setTimeout(() => clearMsg(msg), 2500);
                } catch (err) {
                    showMsg(msg, `❌ ${err.message}`, 'error');
                } finally {
                    btn.disabled    = false;
                    btn.textContent = '💾 ذخیره تولید آینده';
                }
            }

            // ============================================================
            // 7. ریست پیش‌بینی
            // ============================================================
            function resetForecast() {
                if (currentRole === 'viewer') return;
                if (!confirm('همه مقادیر تولید آینده به صفر می‌روند. ادامه می‌دهید؟')) return;
                const dates = Object.keys(forecast);
                const names = activeProductNames();
                dates.forEach(date => {
                    forecast[date] = {};
                    names.forEach(type => {
                        forecast[date][type] = 0;
                    });
                });
                renderForecastTable();
                const msg = document.getElementById('forecastMsg');
                showMsg(msg, '🗑️ همه مقادیر به صفر ریست شدند.', 'error');
                setTimeout(() => clearMsg(msg), 2500);
            }

            // ============================================================
            // 8. ورود و خروج (POST برای ورود)
            // ============================================================
            async function login() {
                const input = document.getElementById('tokenInput').value.trim();
                const msg   = document.getElementById('loginMsg');
                const btn   = document.getElementById('loginBtn');

                if (!input) {
                    showMsg(msg, 'لطفاً توکن را وارد کنید.', 'error');
                    return;
                }

                btn.disabled = true;
                btn.textContent = '⏳ در حال ورود...';
                clearMsg(msg);

                try {
                    const data = await apiPost('login.php', { token: input });
                    currentToken    = input;
                    currentRole     = data.role;
                    currentUserName = data.name;

                    try { localStorage.setItem(TOKEN_STORAGE_KEY, currentToken); } catch (e) {}

                    showMsg(msg, '✅ ورود موفق!', 'success');
                    setTimeout(() => initApp(), 400);
                } catch (err) {
                    showMsg(msg, `❌ ${err.message}`, 'error');
                } finally {
                    btn.disabled    = false;
                    btn.textContent = 'ورود';
                }
            }

            function logout() {
                currentToken    = null;
                currentRole     = null;
                currentUserName = null;
                stock           = {};
                forecast        = {};
                orders          = [];
                products        = [];
                try { localStorage.removeItem(TOKEN_STORAGE_KEY); } catch (e) {}
                initApp();
            }

            async function tryAutoLogin() {
                let savedToken = null;
                try { savedToken = localStorage.getItem(TOKEN_STORAGE_KEY); } catch (e) {}
                if (!savedToken) return;

                try {
                    const data = await apiPost('login.php', { token: savedToken });
                    currentToken    = savedToken;
                    currentRole     = data.role;
                    currentUserName = data.name;
                } catch (e) {
                    try { localStorage.removeItem(TOKEN_STORAGE_KEY); } catch (_) {}
                }
            }

            // ============================================================
            // 9. ثبت سفارش (POST)
            // ============================================================
            async function submitOrder() {
                const type = document.getElementById('orderType').value;
                const qty  = parseInt(document.getElementById('orderQty').value);
                const date = document.getElementById('orderDate').value;
                const msg  = document.getElementById('orderMsg');
                const btn  = document.getElementById('submitOrderBtn');

                if (!type)            { showMsg(msg, 'محصولی برای سفارش موجود نیست.', 'error'); return; }
                if (!qty || qty < 1) { showMsg(msg, 'تعداد معتبر وارد کنید.', 'error'); return; }
                if (!date)           { showMsg(msg, 'تاریخ دریافت را انتخاب کنید.', 'error'); return; }

                btn.disabled    = true;
                btn.textContent = '⏳ در حال ثبت...';
                clearMsg(msg);

                try {
                    await apiPost('submit_order.php', {
                        salt_type:     type,
                        quantity:      qty,
                        delivery_date: date,
                    });
                    showMsg(msg, `✅ سفارش ${qty} عدد ${type} برای ${toJalaliLongString(date)} ثبت شد.`, 'success');
                    await renderMyOrders();
                } catch (err) {
                    showMsg(msg, `❌ ${err.message}`, 'error');
                } finally {
                    btn.disabled    = false;
                    btn.textContent = 'ثبت سفارش';
                }
            }

            // ============================================================
            // 10. رندر سفارشات کاربر (GET)
            // ============================================================
            async function renderMyOrders() {
                const container = document.getElementById('myOrdersList');
                container.innerHTML = '<p class="text-sm">⏳ در حال بارگذاری...</p>';
                try {
                    const userOrders = await apiGet('my_orders.php');
                    if (!userOrders || userOrders.length === 0) {
                        container.innerHTML = '<p class="text-sm">هیچ سفارشی ثبت نشده است.</p>';
                        return;
                    }
                    const statusMap = { confirmed: 'تأیید شده', pending: 'در انتظار', rejected: 'رد شده', delivered: 'تحویل شده' };
                    let html = `<table class="orders-table">
                        <thead><tr><th>نوع</th><th>تعداد</th><th>قیمت واحد</th><th>جمع</th><th>تاریخ دریافت</th><th>تاریخ ثبت</th><th>وضعیت</th><th>عملیات</th></tr></thead><tbody>`;
                    userOrders.forEach(o => {
                        html += `<tr>
                            <td data-label="نوع">${o.salt_type}</td>
                            <td data-label="تعداد">${o.quantity}</td>
                            <td data-label="قیمت واحد">${formatPrice(o.unit_price)} ت</td>
                            <td data-label="جمع">${formatPrice(o.total_price)} ت</td>
                            <td data-label="تاریخ دریافت">${toJalaliString(o.delivery_date)}</td>
                            <td data-label="تاریخ ثبت">${toJalaliString(o.order_date)}</td>
                            <td data-label="وضعیت">${statusMap[o.status] || o.status}</td>
                            <td data-label="عملیات"><button class="btn-del" onclick="deleteOrder(${o.id})" title="حذف">🗑️</button></td>
                        </tr>`;
                    });
                    html += '</tbody></table>';
                    container.innerHTML = html;
                } catch (err) {
                    container.innerHTML = `<p class="text-sm" style="color:#ef4444">⚠️ خطا در بارگذاری: ${err.message}</p>`;
                }
            }

            // ============================================================
            // 11. رندر همه سفارشات (GET)
            // ============================================================
            async function renderAllOrders() {
                const container = document.getElementById('allOrdersList');
                container.innerHTML = '<p class="text-sm">⏳ در حال بارگذاری...</p>';
                try {
                    const allOrders = await apiGet('all_orders.php');
                    if (!allOrders || allOrders.length === 0) {
                        container.innerHTML = '<p class="text-sm">هیچ سفارشی ثبت نشده است.</p>';
                        return;
                    }
                    const statusMap = { confirmed: 'تأیید شده', pending: 'در انتظار', rejected: 'رد شده', delivered: 'تحویل شده' };
                    let html = `<table class="orders-table">
                        <thead><tr><th>کاربر</th><th>نوع</th><th>تعداد</th><th>قیمت واحد</th><th>جمع</th><th>تاریخ دریافت</th><th>تاریخ ثبت</th><th>وضعیت</th><th>عملیات</th></tr></thead><tbody>`;
                    allOrders.forEach(o => {
                        const canDeliver = o.status === 'confirmed' && currentRole === 'admin';
                        html += `<tr>
                            <td data-label="کاربر">${o.user_name}</td>
                            <td data-label="نوع">${o.salt_type}</td>
                            <td data-label="تعداد">${o.quantity}</td>
                            <td data-label="قیمت واحد">${formatPrice(o.unit_price)} ت</td>
                            <td data-label="جمع">${formatPrice(o.total_price)} ت</td>
                            <td data-label="تاریخ دریافت">${toJalaliString(o.delivery_date)}</td>
                            <td data-label="تاریخ ثبت">${toJalaliString(o.order_date)}</td>
                            <td data-label="وضعیت">${statusMap[o.status] || o.status}</td>
                            <td data-label="عملیات"><div style="display:flex;gap:4px;">${canDeliver ? `<button class="btn-deliver" onclick="deliverOrder(${o.id})" title="تحویل">✅</button>` : ''}<button class="btn-del" onclick="deleteOrder(${o.id})" title="حذف">🗑️</button></div></td>
                        </tr>`;
                    });
                    html += '</tbody></table>';
                    container.innerHTML = html;
                } catch (err) {
                    container.innerHTML = `<p class="text-sm" style="color:#ef4444">⚠️ خطا در بارگذاری: ${err.message}</p>`;
                }
            }

            // ============================================================
            // 12. تنظیم تولید (POST)
            // ============================================================
            async function setStock() {
                if (currentRole === 'viewer') return;
                const type = document.getElementById('stockType').value;
                const kg   = parseFloat(document.getElementById('stockQtyKg').value) || 0;
                const pkg  = parseInt(document.getElementById('stockQtyPkg').value) || 0;
                const msg  = document.getElementById('stockMsg');
                const btn  = document.getElementById('setStockBtn');

                if (kg < 0 || pkg < 0) {
                    showMsg(msg, 'مقدار نمی‌تواند منفی باشد.', 'error');
                    return;
                }

                btn.disabled = true;
                clearMsg(msg);

                try {
                    await apiPost('set_stock.php', { salt_type: type, quantity_kg: kg, quantity_pkg: pkg });
                    if (!stock[type]) stock[type] = {};
                    stock[type].quantity_kg = kg;
                    stock[type].quantity_pkg = pkg;
                    showMsg(msg, `✅ تولید ${type}: ${kg} کیلو + ${pkg} بسته`, 'success');
                    updateAdminStockDisplay();
                } catch (err) {
                    showMsg(msg, `❌ ${err.message}`, 'error');
                } finally {
                    btn.disabled = false;
                }
            }

            // ============================================================
            // 12b. مدیریت محصولات و قیمت
            // ============================================================
            async function renderProductList() {
                if (currentRole !== 'admin') return;
                const container = document.getElementById('productList');
                container.innerHTML = '<p class="text-sm">در حال بارگذاری...</p>';
                try {
                    const list = await loadProducts(true);
                    if (!list.length) {
                        container.innerHTML = '<p class="text-sm">هیچ محصولی ثبت نشده است.</p>';
                        return;
                    }
                    container.innerHTML = list.map(p => {
                        const unitIcon = p.unit === 'بسته' ? '📦' : '⚖️';
                        const unitLabel = p.unit === 'بسته' ? `📦 ${p.weight_per_package} کیلو/بسته` : '⚖️ کیلوگرم';
                        return `
                        <div class="product-item ${Number(p.is_active) === 0 ? 'product-inactive' : ''}">
                            <div>
                                <strong>${p.name}</strong>
                                <span class="product-price">${formatPrice(p.price)} ت</span>
                                <span style="font-size:12px;color:#6b7280;margin-right:6px;">${unitLabel}</span>
                            </div>
                            <div class="product-actions">
                            <button class="product-toggle-btn ${Number(p.is_active) === 1 ? 'btn-danger' : 'btn-success'}"
                                    data-id="${p.id}" data-active="${p.is_active}">
                                ${Number(p.is_active) === 1 ? 'غیرفعال' : 'فعال'}
                            </button>
                                <button class="btn-del" onclick="deleteProduct(${p.id}, '${p.name}')" title="حذف">🗑️</button>
                            </div>
                        </div>
                    `}).join('');

                    container.querySelectorAll('.product-toggle-btn').forEach(btn => {
                        btn.addEventListener('click', () => toggleProduct(btn.dataset.id, btn.dataset.active));
                    });

                    fillProductSelects();
                } catch (err) {
                    container.innerHTML = `<p class="text-sm" style="color:#991b1b;">❌ ${err.message}</p>`;
                }
            }

            async function createProduct() {
                if (currentRole !== 'admin') return;
                const nameInput  = document.getElementById('newProductName');
                const priceInput = document.getElementById('newProductPrice');
                const unitSelect = document.getElementById('newProductUnit');
                const weightInput = document.getElementById('newProductWeight');
                const msg = document.getElementById('createProductMsg');
                const btn = document.getElementById('createProductBtn');

                const name   = nameInput.value.trim();
                const price  = parseInt(priceInput.value);
                const unit   = unitSelect.value;
                const weight = parseFloat(weightInput.value) || 0;

                if (!name) { showMsg(msg, '❌ نام محصول را وارد کنید.', 'error'); return; }
                if (isNaN(price) || price < 0) { showMsg(msg, '❌ قیمت معتبر وارد کنید.', 'error'); return; }
                if (unit === 'بسته' && weight <= 0) { showMsg(msg, '❌ وزن هر بسته را وارد کنید.', 'error'); return; }

                btn.disabled = true;
                clearMsg(msg);

                try {
                    await apiPost('create_product.php', { name, price, unit, weight_per_package: weight });
                    showMsg(msg, '✅ محصول اضافه شد.', 'success');
                    nameInput.value = '';
                    priceInput.value = '0';
                    weightInput.value = '1';
                    await renderProductList();
                    renderForecastTable();
                    updateAdminStockDisplay();
                } catch (err) {
                    showMsg(msg, `❌ ${err.message}`, 'error');
                } finally {
                    btn.disabled = false;
                }
            }

            async function toggleProduct(id, currentActive) {
                if (currentRole !== 'admin') return;
                const newActive = Number(currentActive) === 1 ? 0 : 1;
                try {
                    await apiPost('update_product.php', { id: Number(id), is_active: newActive });
                    await renderProductList();
                    renderForecastTable();
                    updateAdminStockDisplay();
                } catch (err) {
                    alert('خطا: ' + err.message);
                }
            }

            // ============================================================
            // 13. مقداردهی اولیه تاریخ
            // ============================================================
            function setDefaultDate() {
                const dateInput = document.getElementById('orderDate');
                if (dateInput) {
                    dateInput.value = getToday();
                }
                updateOrderDatePreview();
            }

            function updateOrderDatePreview() {
                const dateInput = document.getElementById('orderDate');
                const preview = document.getElementById('orderDatePreview');
                if (dateInput && preview) {
                    preview.textContent = dateInput.value
                        ? `📅 معادل شمسی: ${toJalaliLongString(dateInput.value)}`
                        : '';
                }
            }

            // ============================================================
            // 14. اعمال محدودیت نقش «ناظر»
            // ============================================================
            function applyRolePermissions() {
                const isViewer = currentRole === 'viewer';
                const isAdmin  = currentRole === 'admin';

                document.getElementById('viewerNotice').classList.toggle('hidden', !isViewer);
                document.getElementById('tokenMgmtCard').classList.toggle('hidden', !isAdmin);
                document.getElementById('productMgmtCard').classList.toggle('hidden', !isAdmin);

                document.getElementById('stockType').disabled = isViewer;
                document.getElementById('stockQtyKg').disabled = isViewer;
                document.getElementById('stockQtyPkg').disabled = isViewer;
                document.getElementById('setStockBtn').classList.toggle('hidden', isViewer);

                document.getElementById('saveForecastBtn').classList.toggle('hidden', isViewer);
                document.getElementById('resetForecastBtn').classList.toggle('hidden', isViewer);
            }

            // ============================================================
            // 14b. مدیریت توکن‌ها (GET برای لیست، POST برای ساخت)
            // ============================================================
            function roleLabel(role) {
                if (role === 'admin')  return 'مدیر';
                if (role === 'viewer') return 'ناظر';
                return 'کاربر';
            }

            async function renderTokenList() {
                if (currentRole !== 'admin') return;
                const container = document.getElementById('tokenList');
                container.innerHTML = '<p class="text-sm">در حال بارگذاری...</p>';
                try {
                    const list = await apiGet('list_tokens.php');
                    tokens = list || [];
                    if (!list.length) {
                        container.innerHTML = '<p class="text-sm">هیچ توکنی ثبت نشده است.</p>';
                        return;
                    }
                    container.innerHTML = list.map(u => `
                        <div class="token-item ${u.is_active == 0 ? 'token-inactive' : ''}">
                            <div style="display:flex;justify-content:space-between;align-items:center;">
                            <strong>${u.name}</strong>
                            <span class="badge ${u.role === 'admin' ? 'badge-admin' : u.role === 'viewer' ? 'badge-viewer' : ''}">${roleLabel(u.role)}</span>
                            ${u.is_active == 0 ? '<span class="badge" style="background:#fee2e2;color:#991b1b;">غیرفعال</span>' : ''}
                            <code>${u.token}</code>
                                ${u.role !== "admin" ? `<button class="btn-del" onclick="deleteUser(${u.id}, '${u.name}')" title="حذف">🗑️</button>` : ""}
                            </div>
                        </div>
                    `).join('');
                } catch (err) {
                    container.innerHTML = `<p class="text-sm" style="color:#991b1b;">❌ ${err.message}</p>`;
                }
            }

            async function createToken() {
                if (currentRole !== 'admin') return;

                const nameInput = document.getElementById('newTokenName');
                const lastNameInput = document.getElementById('newTokenLastName');
                const phoneInput = document.getElementById('newTokenPhone');
                const roleSelect = document.getElementById('newTokenRole');
                const msg = document.getElementById('createTokenMsg');
                const resultBox = document.getElementById('newTokenResult');
                const btn = document.getElementById('createTokenBtn');

                const name = nameInput.value.trim();
                const lastName = lastNameInput.value.trim();
                const phone = phoneInput.value.trim();
                const role = roleSelect.value;
                const customToken = document.getElementById('newTokenCustom').value.trim();

                if (!name) {
                    showMsg(msg, '❌ نام کاربر را وارد کنید.', 'error');
                    return;
                }
                if (!lastName) {
                    showMsg(msg, '❌ نام خانوادگی را وارد کنید.', 'error');
                    return;
                }
                if (!phone) {
                    showMsg(msg, '❌ شماره تلفن را وارد کنید.', 'error');
                    return;
                }
                if (!/^0[0-9]{10}$/.test(phone)) {
                    showMsg(msg, '❌ شماره تلفن نامعتبر است (فرمت: 09123456789).', 'error');
                    return;
                }

                btn.disabled = true;
                clearMsg(msg);
                resultBox.classList.add('hidden');

                try {
                const payload = { name, last_name: lastName, phone, role };
                if (customToken) payload.custom_token = customToken;
                    const data = await apiPost('create_token.php', payload);
                    showMsg(msg, '✅ توکن جدید ساخته شد. آن را کپی و در جای امن نگه دارید.', 'success');
                    resultBox.innerHTML = `
                        <strong>توکن ${data.name} (${roleLabel(data.role)}):</strong>
                        <code>${data.token}</code>
                    `;
                    resultBox.classList.remove('hidden');
                    nameInput.value = '';
                    lastNameInput.value = '';
                    phoneInput.value = '';
                    roleSelect.value = 'user';
                    document.getElementById('newTokenCustom').value = '';
                    await renderTokenList();
                } catch (err) {
                    showMsg(msg, `❌ ${err.message}`, 'error');
                } finally {
                    btn.disabled = false;
                }
            }

            // ============================================================
            // 15. برچسب نقش
            // ============================================================
            function roleBadgeText() {
                if (currentRole === 'admin') return `🛠 ${currentUserName || 'مدیر'}`;
                if (currentRole === 'viewer') return `👁️ ${currentUserName || 'ناظر'}`;
                return `👤 ${currentUserName || 'کاربر'}`;
            }

            // ============================================================
            // 16. مقداردهی اولیه اپلیکیشن (GET برای دریافت)
            // ============================================================
            async function initApp() {
                document.getElementById('loginPage').classList.add('hidden');
                document.getElementById('userPanel').classList.add('hidden');
                document.getElementById('adminPanel').classList.add('hidden');

                if (!currentToken || !currentRole) {
                    document.getElementById('loginPage').classList.remove('hidden');
                    document.getElementById('tokenInput').value = '';
                    clearMsg(document.getElementById('loginMsg'));
                    return;
                }

                if (currentRole === 'admin' || currentRole === 'viewer') {
                    document.getElementById('adminPanel').classList.remove('hidden');
                    document.getElementById('adminRoleBadge').textContent = roleBadgeText();
                    document.getElementById('adminRoleBadge').className =
                        'role-badge ' + (currentRole === 'viewer' ? 'badge-viewer' : 'badge-admin');

                    try {
                        await loadProducts(currentRole === 'admin');
                    } catch (_) {}
                    fillProductSelects();

                    try {
                        const serverStock = await apiGet('get_stock.php');
                        Object.assign(stock, serverStock);
                    } catch (_) {}

                    try {
                        const serverForecast = await apiGet('get_forecast.php');
                        forecast = serverForecast;
                    } catch (_) {
                        initForecast();
                    }

                    renderForecastTable();
                    await renderAllOrders();
                    updateAdminStockDisplay();
                    applyRolePermissions();

                    if (currentRole === 'admin') {
                        await renderTokenList();
                        await renderProductList();
                    }

                } else if (currentRole === 'user') {
                    document.getElementById('userPanel').classList.remove('hidden');
                    document.getElementById('userRoleBadge').textContent = roleBadgeText();
                    try {
                        await loadProducts(false);
                    } catch (_) {}
                    fillProductSelects();
                    setDefaultDate();
                    await renderMyOrders();
                }
                // Re-init collapsible after all data loads
                setTimeout(() => initCollapsible(), 100);
            }

            // ============================================================
            // 17. اتصال رویدادها
            // ============================================================
            function bindEvents() {
                document.getElementById('loginBtn').addEventListener('click', login);
                document.getElementById('logoutUserBtn').addEventListener('click', logout);
                document.getElementById('logoutAdminBtn').addEventListener('click', logout);
                document.getElementById('submitOrderBtn').addEventListener('click', submitOrder);
                document.getElementById('setStockBtn').addEventListener('click', setStock);
                document.getElementById('saveForecastBtn').addEventListener('click', saveForecast);
                document.getElementById('renderForecastBtn').addEventListener('click', renderForecastTable);
                document.getElementById('resetForecastBtn').addEventListener('click', resetForecast);
                document.getElementById('createTokenBtn').addEventListener('click', createToken);
                document.getElementById('createProductBtn').addEventListener('click', createProduct);

                // ─── toggle weight field when unit changes ───
                document.getElementById('newProductUnit').addEventListener('change', function() {
                    document.getElementById('pkgWeightDiv').style.display = this.value === 'بسته' ? '' : 'none';
                });

                document.getElementById('tokenInput').addEventListener('keydown', function(e) {
                    if (e.key === 'Enter') login();
                });

                const orderDateInput = document.getElementById('orderDate');
                if (orderDateInput) {
                    orderDateInput.addEventListener('change', updateOrderDatePreview);
                }
                const orderTypeSelect = document.getElementById('orderType');
                if (orderTypeSelect) {
                    orderTypeSelect.addEventListener('change', updateOrderPricePreview);
                }
                const orderQtyInput = document.getElementById('orderQty');
                if (orderQtyInput) {
                    orderQtyInput.addEventListener('input', updateOrderTotalPreview);
                }
            }

            // ============================================================

            // ============================================================
            // 19. توابع حذف
            // ============================================================
            window.deleteProduct = async function(id, name) {
                if (!confirm("محصول \"" + name + "\" حذف شود؟")) return;
                try {
                    await apiPost("delete_product.php", { id: id });
                    alert("✅ محصول حذف شد.");
                    await renderProductList();
                    renderForecastTable();
                    updateAdminStockDisplay();
                } catch(err) { alert("❌ " + err.message); }
            };

            window.deleteUser = async function(id, name) {
                if (!confirm("کاربر \"" + name + "\" و سفارشاتش حذف شود؟")) return;
                try {
                    await apiPost("delete_user.php", { id: id });
                    alert("✅ کاربر حذف شد.");
                    await renderTokenList();
                } catch(err) { alert("❌ " + err.message); }
            };

            window.deleteOrder = async function(id) {
                if (!confirm("سفارش #" + id + " حذف شود؟ تولید بازمی‌گردد.")) return;
                try {
                    await apiPost("delete_order.php", { id: id });
                    alert("✅ سفارش حذف شد.");
                    await renderMyOrders();
                    await renderAllOrders();
                } catch(err) { alert("❌ " + err.message); }
            };

            // ============================================================
            // 20. توابع اکسل (خروجی و ورودی)
            // ============================================================
            function downloadCSV(filename, csvContent) {
                const BOM = '\uFEFF';
                const blob = new Blob([BOM + csvContent], { type: 'text/csv;charset=utf-8;' });
                const url = URL.createObjectURL(blob);
                const a = document.createElement('a');
                a.href = url;
                a.download = filename;
                a.click();
                URL.revokeObjectURL(url);
            }

            function toCSVRow(values) {
                return values.map(v => {
                    const s = String(v ?? '');
                    return s.includes(',') || s.includes('"') || s.includes('\n')
                        ? '"' + s.replace(/"/g, '""') + '"' : s;
                }).join(',');
            }

            window.exportOrdersCSV = async function() {
                const csvMsg = document.getElementById('csvMsg');
                try {
                    showMsg(csvMsg, '⏳ در حال دریافت سفارشات...', 'success');
                    const allOrders = await apiGet('all_orders.php');
                    if (!allOrders || allOrders.length === 0) {
                        showMsg(csvMsg, '⚠️ سفارشی وجود ندارد.', 'error');
                        return;
                    }
                    let csv = toCSVRow(['کاربر', 'نوع', 'تعداد', 'قیمت واحد', 'جمع', 'تاریخ دریافت', 'تاریخ ثبت', 'وضعیت']);
                    allOrders.forEach(o => {
                        csv += '\n' + toCSVRow([o.user_name, o.salt_type, o.quantity, o.unit_price, o.total_price, toJalaliLongString(o.delivery_date), toJalaliLongString(o.order_date), o.status]);
                    });
                    downloadCSV('orders_' + getToday() + '.csv', csv);
                    showMsg(csvMsg, '✅ فایل سفارشات دانلود شد.', 'success');
                    setTimeout(() => clearMsg(csvMsg), 2500);
                } catch(err) { showMsg(csvMsg, '❌ ' + err.message, 'error'); }
            };

            window.exportStockCSV = async function() {
                const csvMsg = document.getElementById('csvMsg');
                try {
                    showMsg(csvMsg, '⏳ در حال دریافت تولید...', 'success');
                    const serverStock = await apiGet('get_stock.php');
                    let csv = toCSVRow(['نوع نمک', 'کیلوگرم', 'بسته']);
                    Object.entries(serverStock).forEach(([type, info]) => {
                        csv += '\n' + toCSVRow([type, info.quantity_kg || 0, info.quantity_pkg || 0]);
                    });
                    downloadCSV('stock_' + getToday() + '.csv', csv);
                    showMsg(csvMsg, '✅ فایل تولید دانلود شد.', 'success');
                    setTimeout(() => clearMsg(csvMsg), 2500);
                } catch(err) { showMsg(csvMsg, '❌ ' + err.message, 'error'); }
            };

            window.exportForecastCSV = async function() {
                const csvMsg = document.getElementById('csvMsg');
                try {
                    showMsg(csvMsg, '⏳ در حال دریافت پیش‌بینی...', 'success');
                    const serverForecast = await apiGet('get_forecast.php');
                    const dates = Object.keys(serverForecast).sort();
                    const names = activeProductNames();
                    let csv = toCSVRow(['تاریخ', ...names]);
                    dates.forEach(date => {
                        csv += '\n' + toCSVRow([date, ...names.map(n => serverForecast[date]?.[n] ?? 0)]);
                    });
                    downloadCSV('forecast_' + getToday() + '.csv', csv);
                    showMsg(csvMsg, '✅ فایل تولید آینده دانلود شد.', 'success');
                    setTimeout(() => clearMsg(csvMsg), 2500);
                } catch(err) { showMsg(csvMsg, '❌ ' + err.message, 'error'); }
            };

            window.importOrdersCSV = function(input) {
                const csvMsg = document.getElementById('csvMsg');
                const file = input.files[0];
                if (!file) return;
                const reader = new FileReader();
                reader.onload = async function(e) {
                    try {
                        showMsg(csvMsg, '⏳ در حال پردازش فایل...', 'success');
                        const lines = e.target.result.split('\n').filter(l => l.trim());
                        if (lines.length < 2) {
                            showMsg(csvMsg, '⚠️ فایل خالی است یا فقط هدر دارد.', 'error');
                            return;
                        }
                        // هدر رد می‌شه، ردیف‌های بعد داده هستن
                        const rows = lines.slice(1).map(l => {
                            const parts = l.split(',');
                            return { type: parts[0]?.trim(), qty: parseInt(parts[1]?.trim()) || 0, date: parts[2]?.trim() };
                        });
                        // بررسی اینکه تاریخ‌ها معتبرن و المنت‌ها درستن
                        const valid = rows.filter(r => r.type && r.qty > 0 && /^\d{4}-\d{2}-\d{2}$/.test(r.date));
                        if (valid.length === 0) {
                            showMsg(csvMsg, '⚠️ هیچ ردیف معتبری یافت نشد. فرمت: نوع,تعداد,تاریخ(YYYY-MM-DD)', 'error');
                            return;
                        }
                        showMsg(csvMsg, `⏳ ثبت ${valid.length} سفارش...`, 'success');
                        let ok = 0, fail = 0;
                        for (const r of valid) {
                            try {
                                await apiPost('submit_order.php', { salt_type: r.type, quantity: r.qty, delivery_date: r.date });
                                ok++;
                            } catch(_) { fail++; }
                        }
                        showMsg(csvMsg, `✅ ${ok} سفارش ثبت شد. ${fail > 0 ? '❌ ' + fail + ' ردیف ناموفق.' : ''}`, ok > 0 ? 'success' : 'error');
                        await renderAllOrders();
                        await renderMyOrders();
                    } catch(err) { showMsg(csvMsg, '❌ ' + err.message, 'error'); }
                    input.value = '';
                };
                reader.readAsText(file);
            };


            // ============================================================
            // 22. جستجو
            // ============================================================
            window.handleSearch = function(query) {
                const resultsEl = document.getElementById("searchResults");
                if (!query || query.length < 2) {
                    resultsEl.innerHTML = "";
                    return;
                }
                const q = query.toLowerCase();
                let results = [];
                
                orders.forEach(o => {
                    const match = (o.salt_type || "").toLowerCase().includes(q) ||
                                  (o.user_name || "").toLowerCase().includes(q) ||
                                  (o.delivery_date || "").includes(q);
                    if (match) results.push({type: "order", data: o});
                });
                
                tokens.forEach(t => {
                    const match = (t.name || "").toLowerCase().includes(q) ||
                                  (t.phone || "").toLowerCase().includes(q) ||
                                  (t.token || "").toLowerCase().includes(q);
                    if (match) results.push({type: "user", data: t});
                });
                
                products.forEach(p => {
                    const match = (p.name || "").toLowerCase().includes(q);
                    if (match) results.push({type: "product", data: p});
                });
                
                if (results.length === 0) {
                    resultsEl.innerHTML = '<div style="padding:16px;text-align:center;color:#9ca3af;font-size:13px;">🔍 نتیجه‌ای یافت نشد</div>';
                    return;
                }
                
                let html = '<div style="max-height:400px;overflow-y:auto;">';
                const groupIcons = {order:'📋', user:'👤', product:'🧂'};
                const groupLabels = {order:'سفارشات', user:'کاربران', product:'محصولات'};
                const grouped = {};
                results.slice(0, 20).forEach(r => {
                    if (!grouped[r.type]) grouped[r.type] = [];
                    grouped[r.type].push(r);
                });
                for (const [type, items] of Object.entries(grouped)) {
                    html += '<div style="padding:6px 12px;background:#f3f4f6;font-size:11px;font-weight:600;color:#6b7280;text-transform:uppercase;letter-spacing:0.5px;">' + groupIcons[type] + ' ' + groupLabels[type] + ' (' + items.length + ')</div>';
                    items.forEach(r => {
                        let body = '';
                        let clickAction = '';
                        if (r.type === 'order') {
                            const st = r.data.status === 'delivered' ? '✅' : (r.data.status === 'confirmed' ? '⏳' : '❌');
                            body = '<div style="display:flex;justify-content:space-between;align-items:center;"><div><strong style="color:#1f2937;">' + r.data.salt_type + '</strong> × ' + r.data.quantity + '<span style="color:#6b7280;font-size:12px;margin:0 6px;">•</span>' + r.data.user_name + '</div><div style="display:flex;align-items:center;gap:8px;"><span style="color:#6b7280;font-size:12px;">' + toJalaliString(r.data.delivery_date) + '</span><span>' + st + '</span></div></div>';
                            clickAction = 'onclick="document.getElementById(\"allOrdersList\").scrollIntoView({behavior:\"smooth\"})"';
                        } else if (r.type === 'user') {
                            body = '<div style="display:flex;justify-content:space-between;align-items:center;"><div><strong style="color:#1f2937;">' + r.data.name + '</strong><span style="color:#6b7280;font-size:12px;margin:0 6px;">•</span>' + (r.data.phone || '') + '</div><code style="font-size:11px;color:#7c3aed;background:#f3f0ff;padding:2px 6px;border-radius:4px;">' + r.data.token + '</code></div>';
                            clickAction = 'onclick="document.getElementById(\"tokenList\").scrollIntoView({behavior:\"smooth\"})"';
                        } else if (r.type === 'product') {
                            const unitLabel = r.data.unit === 'بسته' ? '📦 ' + r.data.weight_per_package + 'کیلو/بسته' : '⚖️ کیلوگرم';
                            body = '<div style="display:flex;justify-content:space-between;align-items:center;"><div><strong style="color:#1f2937;">' + r.data.name + '</strong><span style="color:#6b7280;font-size:12px;margin:0 6px;">•</span>' + unitLabel + '</div><span style="font-weight:600;color:#059669;">' + formatPrice(r.data.price) + ' ت</span></div>';
                            clickAction = 'onclick="document.getElementById(\"productMgmtCard\").scrollIntoView({behavior:\"smooth\"})"';
                        }
                        html += '<div style="padding:10px 12px;border-bottom:1px solid #f3f4f6;font-size:13px;cursor:pointer;transition:background 0.15s;" ' + clickAction + ' onmouseover="this.style.background=\'#f9fafb\'" onmouseout="this.style.background=\'transparent\'">' + body + '</div>';
                    });
                }
                html += '</div>';
                resultsEl.innerHTML = html;
            };

            // ============================================================
            // 23. تحویل سفارش
            // ============================================================
            window.deliverOrder = async function(orderId) {
                if (!confirm("آیا مطمئن هستید که این سفارش تحویل داده شده؟")) return;
                try {
                    await apiPost("deliver_order.php", { order_id: orderId });
                    alert("✅ سفارش تحویل داده شد و تولید کسر شد.");
                    await renderAllOrders();
                    updateAdminStockDisplay();
                } catch(err) {
                    alert("❌ " + err.message);
                }
            };

            // 18. اجرای اولیه
            // ============================================================
            // ============================================================
            // 24. اکسل جدید — فیلترشده
            // ============================================================
            window.exportFilteredOrdersCSV = async function(filter) {
                const csvMsg = document.getElementById('csvMsg');
                try {
                    showMsg(csvMsg, '⏳ در حال دریافت سفارشات...', 'success');
                    const allOrders = await apiGet('all_orders.php');
                    if (!allOrders || !allOrders.length) {
                        showMsg(csvMsg, '⚠️ سفارشی وجود ندارد.', 'error');
                        return;
                    }
                    const today = new Date();
                    const todayStr = today.toISOString().slice(0, 10);
                    const tomorrow = new Date(today); tomorrow.setDate(tomorrow.getDate() + 1);
                    const tomorrowStr = tomorrow.toISOString().slice(0, 10);
                    const weekStart = new Date(today); weekStart.setDate(today.getDate() - today.getDay() + (today.getDay() === 6 ? -6 : 1));
                    const weekEnd = new Date(weekStart); weekEnd.setDate(weekStart.getDate() + 6);
                    let filtered = allOrders;
                    if (filter === 'today') filtered = allOrders.filter(o => o.delivery_date === todayStr);
                    else if (filter === 'tomorrow') filtered = allOrders.filter(o => o.delivery_date === tomorrowStr);
                    else if (filter === 'week') filtered = allOrders.filter(o => o.delivery_date >= weekStart.toISOString().slice(0, 10) && o.delivery_date <= weekEnd.toISOString().slice(0, 10));
                    else if (filter === 'confirmed') filtered = allOrders.filter(o => o.status === 'confirmed');
                    else if (filter === 'delivered') filtered = allOrders.filter(o => o.status === 'delivered');
                    if (!filtered.length) {
                        showMsg(csvMsg, '⚠️ نتیجه‌ای برای فیلتر انتخاب شده وجود ندارد.', 'error');
                        return;
                    }
                    let csv = toCSVRow(['کاربر', 'نوع', 'تعداد', 'واحد', 'قیمت واحد', 'جمع', 'تاریخ دریافت', 'تاریخ ثبت', 'وضعیت']);
                    filtered.forEach(o => {
                        const prod = products.find(p => p.name === o.salt_type);
                        const unit = prod ? prod.unit : '';
                        csv += '\n' + toCSVRow([o.user_name, o.salt_type, o.quantity, unit, o.unit_price, o.total_price, toJalaliLongString(o.delivery_date), toJalaliLongString(o.order_date), o.status]);
                    });
                    const labels = {all:'همه', today:'امروز', tomorrow:'فردا', week:'هفته', confirmed:'در_انتظار', delivered:'تحویل_شده'};
                    downloadCSV('orders_' + (labels[filter] || filter) + '_' + getToday() + '.csv', csv);
                    showMsg(csvMsg, `✅ ${filtered.length} سفارش خروجی گرفته شد.`, 'success');
                    setTimeout(() => clearMsg(csvMsg), 2500);
                } catch(err) { showMsg(csvMsg, '❌ ' + err.message, 'error'); }
            };

            window.exportUsersCSV = async function() {
                const csvMsg = document.getElementById('csvMsg');
                try {
                    showMsg(csvMsg, '⏳ ...', 'success');
                    const list = await apiGet('list_tokens.php');
                    if (!list || !list.length) {
                        showMsg(csvMsg, '⚠️ کاربری وجود ندارد.', 'error');
                        return;
                    }
                    let csv = toCSVRow(['نام', 'نام خانوادگی', 'تلفن', 'توکن', 'نقش', 'وضعیت']);
                    list.forEach(u => {
                        csv += '\n' + toCSVRow([u.name || '', u.last_name || '', u.phone || '', u.token, u.role, u.is_active ? 'فعال' : 'غیرفعال']);
                    });
                    downloadCSV('users_' + getToday() + '.csv', csv);
                    showMsg(csvMsg, `✅ ${list.length} کاربر خروجی گرفته شد.`, 'success');
                    setTimeout(() => clearMsg(csvMsg), 2500);
                } catch(err) { showMsg(csvMsg, '❌ ' + err.message, 'error'); }
            };

            window.exportUsersWithOrdersCSV = async function() {
                const csvMsg = document.getElementById('csvMsg');
                try {
                    showMsg(csvMsg, '⏳ ...', 'success');
                    const [list, allOrders] = await Promise.all([apiGet('list_tokens.php'), apiGet('all_orders.php')]);
                    const userOrderMap = {};
                    (allOrders || []).forEach(o => {
                        if (!userOrderMap[o.user_name]) userOrderMap[o.user_name] = [];
                        userOrderMap[o.user_name].push(o);
                    });
                    let csv = toCSVRow(['نام', 'تلفن', 'توکن', 'نقش', 'تعداد سفارش', 'مجموع خرید', 'آخرین سفارش']);
                    (list || []).forEach(u => {
                        const uOrders = userOrderMap[u.name] || [];
                        const totalSpent = uOrders.reduce((s, o) => s + (parseInt(o.total_price) || 0), 0);
                        const lastOrder = uOrders.length ? toJalaliLongString(uOrders[uOrders.length - 1].delivery_date) : '-';
                        csv += '\n' + toCSVRow([u.name + ' ' + (u.last_name || ''), u.phone || '', u.token, u.role, uOrders.length, totalSpent, lastOrder]);
                    });
                    downloadCSV('users_orders_' + getToday() + '.csv', csv);
                    showMsg(csvMsg, `✅ گزارش کاربران و سفارشات دانلود شد.`, 'success');
                    setTimeout(() => clearMsg(csvMsg), 2500);
                } catch(err) { showMsg(csvMsg, '❌ ' + err.message, 'error'); }
            };

            window.importForecastCSV = function(input) {
                const csvMsg = document.getElementById('csvMsg');
                const file = input.files[0];
                if (!file) return;
                const reader = new FileReader();
                reader.onload = async function(e) {
                    try {
                        showMsg(csvMsg, '⏳ در حال پردازش...', 'success');
                        const lines = e.target.result.split('\n').filter(l => l.trim());
                        if (lines.length < 2) { showMsg(csvMsg, '⚠️ فایل خالی است.', 'error'); return; }
                        const names = activeProductNames();
                        const rows = [];
                        for (let i = 1; i < lines.length; i++) {
                            const cols = lines[i].split(',').map(c => c.trim().replace(/^"|"$/g, ''));
                            if (cols.length < 2) continue;
                            const date = cols[0];
                            names.forEach((n, idx) => {
                                const qty = parseInt(cols[idx + 1]) || 0;
                                rows.push({ salt_type: n, forecast_date: date, quantity: qty });
                            });
                        }
                        await apiPost('save_forecast.php', { rows });
                        showMsg(csvMsg, `✅ ${rows.length} ردیف پیش‌بینی وارد شد.`, 'success');
                        setTimeout(() => { clearMsg(csvMsg); closeExcelModal(); renderForecastTable(); }, 1500);
                    } catch(err) { showMsg(csvMsg, '❌ ' + err.message, 'error'); }
                };
                reader.readAsText(file);
                input.value = '';
            };

            // ============================================================
            // 25. باز/بسته کردن بخش‌ها
            // ============================================================
            window.openExcelModal = function() {
                document.getElementById('excelModal').style.display = 'block';
            };
            window.closeExcelModal = function() {
                document.getElementById('excelModal').style.display = 'none';
            };

            function initCollapsible() {
                document.querySelectorAll('#adminPanel .card > h3').forEach(h3 => {
                    const card = h3.closest('.card');
                    if (!card) return;
                    if (card.querySelector('[onclick*="openExcelModal"]')) return;
                    const bodyItems = [];
                    for (const child of card.children) {
                        if (child !== h3) bodyItems.push(child);
                    }
                    if (!bodyItems.length) return;
                    h3.classList.add('collapse-toggle');
                    h3.style.cursor = 'pointer';
                    const wrapper = document.createElement('div');
                    wrapper.classList.add('collapse-body');
                    bodyItems.forEach(el => wrapper.appendChild(el));
                    card.appendChild(wrapper);
                    // Start collapsed by default (except search bar)
                    if (!h3.textContent.includes('جستجو')) {
                        wrapper.classList.add('collapsed');
                        h3.classList.add('collapsed');
                    } else {
                        wrapper.style.maxHeight = wrapper.scrollHeight + 'px';
                    }
                    h3.addEventListener('click', function(e) {
                        e.stopPropagation();
                        if (wrapper.classList.contains('collapsed')) {
                            wrapper.classList.remove('collapsed');
                            h3.classList.remove('collapsed');
                            wrapper.style.maxHeight = wrapper.scrollHeight + 'px';
                        } else {
                            wrapper.classList.add('collapsed');
                            h3.classList.add('collapsed');
                        }
                    });
                });
            }

            // ============================================================
            // 26. تقویم شمسی سفارشی
            // ============================================================
            function gregorianToJalali(gy, gm, gd) {
                const g_d_m = [0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334];
                let gy2 = (gm > 2) ? (gy + 1) : gy;
                let days = 355666 + (365 * gy) + Math.floor((gy2 + 3) / 4) - Math.floor((gy2 + 99) / 100) + Math.floor((gy2 + 399) / 400) + gd + g_d_m[gm - 1];
                let jy = -1595 + (33 * Math.floor(days / 12053));
                days %= 12053;
                jy += 4 * Math.floor(days / 1461);
                days %= 1461;
                if (days > 365) { jy += Math.floor((days - 1) / 365); days = (days - 1) % 365; }
                let jm, jd;
                if (days < 186) { jm = 1 + Math.floor(days / 31); jd = 1 + (days % 31); }
                else { days -= 186; jm = 7 + Math.floor(days / 30); jd = 1 + (days % 30); }
                return [jy, jm, jd];
            }

            function jalaliToGregorian(jy, jm, jd) {
                let jy2 = jy + 1595;
                let days = -355668 + (365 * jy) + (Math.floor(jy / 33) * 8) + Math.floor(((jy % 33) + 3) / 4) + jd + ((jm < 7) ? (jm - 1) * 31 : ((jm - 7) * 30) + 186);
                let gy = 400 * Math.floor(days / 146097);
                days %= 146097;
                if (days > 36524) { gy += 100 * Math.floor(--days / 36524); days %= 36524; if (days >= 365) days++; }
                gy += 4 * Math.floor(days / 1461); days %= 1461;
                if (days > 365) { gy += Math.floor((days - 1) / 365); days = (days - 1) % 365; }
                let gd = days + 1;
                const salA = [0, 31, ((gy % 4 === 0 && gy % 100 !== 0) || (gy % 400 === 0)) ? 29 : 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];
                let gm;
                for (gm = 0; gm < 13 && gd > salA[gm]; gm++) gd -= salA[gm];
                return [gy, gm, gd];
            }

            function toJalaliStringShort(gy, gm, gd) {
                const [jy, jm, jd] = gregorianToJalali(gy, gm, gd);
                return `${jy}/${String(jm).padStart(2,'0')}/${String(jd).padStart(2,'0')}`;
            }

            function showShamsiPicker(inputEl, hiddenInput) {
                const today = new Date();
                let currentMonth = gregorianToJalali(today.getFullYear(), today.getMonth() + 1, today.getDate());
                let currentYear = currentMonth[0];
                let currentJM = currentMonth[1];
                const dayNames = ['ش', 'ی', 'د', 'س', 'چ', 'پ', 'ج'];
                const monthNames = ['فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور', 'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند'];
                let dropdown = inputEl.parentNode.querySelector('.shamsi-dropdown');
                if (!dropdown) {
                    dropdown = document.createElement('div');
                    dropdown.classList.add('shamsi-dropdown');
                    inputEl.parentNode.appendChild(dropdown);
                }
                function render() {
                    const firstDay = jalaliToGregorian(currentYear, currentJM, 1);
                    const firstDayDate = new Date(firstDay[0], firstDay[1] - 1, firstDay[2]);
                    let startDow = firstDayDate.getDay();
                    startDow = (startDow + 1) % 7;
                    const daysInMonth = currentJM <= 6 ? 31 : (currentJM <= 11 ? 30 : (currentYear % 4 === 3 ? 30 : 29));
                    const todayJ = gregorianToJalali(today.getFullYear(), today.getMonth() + 1, today.getDate());
                    let html = '<div class="shamsi-header">';
                    html += '<button type="button" onclick="event.stopPropagation()">◀</button>';
                    html += '<strong>' + monthNames[currentJM - 1] + ' ' + currentYear + '</strong>';
                    html += '<button type="button" onclick="event.stopPropagation()">▶</button>';
                    html += '</div>';
                    html += '<div class="shamsi-grid">';
                    dayNames.forEach(d => html += '<div class="day-name">' + d + '</div>');
                    for (let i = 0; i < startDow; i++) html += '<div class="day-cell empty"></div>';
                    for (let d = 1; d <= daysInMonth; d++) {
                        const isToday = currentYear === todayJ[0] && currentJM === todayJ[1] && d === todayJ[2];
                        const cls = isToday ? 'day-cell today' : 'day-cell';
                        html += '<div class="' + cls + '" data-day="' + d + '">' + d + '</div>';
                    }
                    html += '</div>';
                    dropdown.innerHTML = html;
                    dropdown.querySelectorAll('.day-cell:not(.empty)').forEach(cell => {
                        cell.addEventListener('click', function(e) {
                            e.stopPropagation();
                            const day = parseInt(this.dataset.day);
                            const greg = jalaliToGregorian(currentYear, currentJM, day);
                            const gregStr = greg[0] + '-' + String(greg[1]).padStart(2, '0') + '-' + String(greg[2]).padStart(2, '0');
                            const jalaliStr = toJalaliStringShort(greg[0], greg[1], greg[2]);
                            hiddenInput.value = gregStr;
                            inputEl.value = jalaliStr + ' 📅';
                            dropdown.classList.remove('show');
                        });
                    });
                    const prevBtn = dropdown.querySelector('.shamsi-header button:first-child');
                    const nextBtn = dropdown.querySelector('.shamsi-header button:last-child');
                    prevBtn.addEventListener('click', function(e) {
                        e.stopPropagation();
                        currentJM--;
                        if (currentJM < 1) { currentJM = 12; currentYear--; }
                        render();
                    });
                    nextBtn.addEventListener('click', function(e) {
                        e.stopPropagation();
                        currentJM++;
                        if (currentJM > 12) { currentJM = 1; currentYear++; }
                        render();
                    });
                }
                dropdown.classList.add('show');
                render();
            }

            function initShamsiDatePickers() {
                const orderDateInput = document.getElementById('orderDate');
                if (!orderDateInput) return;
                const wrapper = orderDateInput.parentNode;
                wrapper.style.position = 'relative';
                const hiddenInput = orderDateInput;
                const displayInput = document.createElement('input');
                displayInput.type = 'text';
                displayInput.readOnly = true;
                displayInput.placeholder = '📅 انتخاب تاریخ شمسی';
                displayInput.style.cssText = 'width:100%;padding:10px 14px;border:1px solid #d1d5db;border-radius:8px;font-size:14px;cursor:pointer;text-align:center;background:#f9fafb;';
                displayInput.id = 'orderDateDisplay';
                hiddenInput.type = 'hidden';
                hiddenInput.style.display = 'none';
                wrapper.insertBefore(displayInput, hiddenInput);
                displayInput.addEventListener('click', function(e) {
                    e.stopPropagation();
                    document.querySelectorAll('.shamsi-dropdown').forEach(d => d.classList.remove('show'));
                    showShamsiPicker(displayInput, hiddenInput);
                });
            }

            // Initialize after DOM ready
            setTimeout(() => {
                initCollapsible();
                initShamsiDatePickers();
            }, 100);

            (async () => {
                bindEvents();
                await tryAutoLogin();
                await initApp();
            })();

        })();
    