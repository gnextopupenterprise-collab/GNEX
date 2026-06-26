const CUSTOMER_SHEET_BASE_URL = "https://docs.google.com/spreadsheets/d/e/2PACX-1vTivUAgNTsrxtYk9_vkOSbk0b9ygF-5l0z0dUvQp0HMhsHYtM2H7_YwG9MeExUCfvrfMtdtgXIKXBBL/pub";
const CUSTOMER_SHEET_GID = window.CUSTOMER_SHEET_GID || "2059103900";
const CUSTOMER_SHEET_URL = `${CUSTOMER_SHEET_BASE_URL}?gid=${CUSTOMER_SHEET_GID}&single=true&output=csv`;
const GAME_ID_VISIBLE_PREFIX = window.GAME_ID_VISIBLE_PREFIX || 0;

let rankingCustomers = [];

function openRankingModal(){
  const modal = document.getElementById("rankingModal");
  modal.classList.remove("hidden");

  if(rankingCustomers.length === 0){
    loadRankingCustomer();
  }
}

function closeRankingModal(){
  const modal = document.getElementById("rankingModal");
  modal.classList.add("hidden");
}

async function loadRankingCustomer(){
  const rankingTable = document.getElementById("rankingTable");

  rankingTable.innerHTML = `
    <tr>
      <td colspan="5" class="p-4 text-zinc-500 text-center">
        Loading ranking customer...
      </td>
    </tr>
  `;

  try{
    const response = await fetch(CUSTOMER_SHEET_URL);
    const csvText = await response.text();

    if(
      csvText.includes("<html") || 
      csvText.includes("var gidMatch") || 
      csvText.includes("<!DOCTYPE html")
    ){
      rankingTable.innerHTML = `
        <tr>
          <td colspan="5" class="p-4 text-red-400 text-center">
            Link Google Sheet salah. Guna link CSV Publish to web.
          </td>
        </tr>
      `;
      return;
    }

    const rows = parseCSV(csvText);

    rankingCustomers = rows.slice(1).map(row => {
      return {
        number: row[0] || "",
        id: row[1] || "",
        rm: row[2] || "",
        repeat: row[3] || "0"
      };
    });

    rankingCustomers = rankingCustomers.filter(item => {
      return item.number || item.id || item.rm || item.repeat;
    });

    rankingCustomers.sort((a, b) => {
      return getNumberOnly(b.rm) - getNumberOnly(a.rm);
    });

    rankingCustomers = rankingCustomers.map((item, index) => {
      return {
        ...item,
        rank: index + 1
      };
    });

    renderRankingTable();

  }catch(error){
    rankingTable.innerHTML = `
      <tr>
        <td colspan="5" class="p-4 text-red-400 text-center">
          Data gagal load. Check link CSV Google Sheet.
        </td>
      </tr>
    `;
  }
}

function renderRankingTable(){
  const rankingTable = document.getElementById("rankingTable");
  const searchInput = document.getElementById("rankingSearch");
  const searchValue = searchInput.value.toLowerCase().trim();

  let data = rankingCustomers;

  if(searchValue){
    data = rankingCustomers.filter(item => {
      return item.number.toLowerCase().includes(searchValue) ||
             item.id.toLowerCase().includes(searchValue);
    });
  }

  if(data.length === 0){
    rankingTable.innerHTML = `
      <tr>
        <td colspan="5" class="p-4 text-zinc-500 text-center">
          Tiada customer dijumpai.
        </td>
      </tr>
    `;
    return;
  }

  rankingTable.innerHTML = data.map(item => {
    return `
      <tr class="border-b border-zinc-800 hover:bg-cyan-400/5 transition">
        <td class="p-2 md:p-3 text-amber-300 font-black text-[10px] md:text-sm">
          ${item.rank}
        </td>

        <td class="p-2 md:p-3 font-bold text-[10px] md:text-sm break-words">
          ${maskCustomerNumber(item.number)}
        </td>

        <td class="p-2 md:p-3 font-bold text-[10px] md:text-sm break-words">
          ${maskGameId(item.id)}
        </td>

        <td class="p-2 md:p-3 text-cyan-300 font-black text-[10px] md:text-sm break-words">
          ${formatRM(item.rm)}
        </td>

        <td class="p-2 md:p-3 font-black text-[10px] md:text-sm">
          ${item.repeat}
        </td>
      </tr>
    `;
  }).join("");
}

function parseCSV(text){
  const rows = [];
  let currentRow = [];
  let currentCell = "";
  let insideQuote = false;

  for(let i = 0; i < text.length; i++){
    const char = text[i];
    const nextChar = text[i + 1];

    if(char === '"' && insideQuote && nextChar === '"'){
      currentCell += '"';
      i++;
    }else if(char === '"'){
      insideQuote = !insideQuote;
    }else if(char === "," && !insideQuote){
      currentRow.push(currentCell.trim());
      currentCell = "";
    }else if((char === "\n" || char === "\r") && !insideQuote){
      if(currentCell || currentRow.length){
        currentRow.push(currentCell.trim());
        rows.push(currentRow);
        currentRow = [];
        currentCell = "";
      }
    }else{
      currentCell += char;
    }
  }

  if(currentCell || currentRow.length){
    currentRow.push(currentCell.trim());
    rows.push(currentRow);
  }

  return rows;
}

function maskCustomerNumber(number){
  if(!number) return "-";

  const clean = String(number).trim();

  if(clean.toLowerCase() === "number"){
    return "-";
  }

  if(clean.length <= 4){
    return clean + " ****";
  }

  return clean.slice(0, -4) + "****";
}

function maskGameId(id){
  if(!id) return "-";

  const clean = String(id).trim();

  if(GAME_ID_VISIBLE_PREFIX > 0){
    if(clean.length <= GAME_ID_VISIBLE_PREFIX){
      return clean + "***";
    }

    return clean.slice(0, GAME_ID_VISIBLE_PREFIX) + "***";
  }

  if(clean.length <= 3){
    return "***";
  }

  return clean.slice(0, -3) + "***";
}

function formatRM(value){
  if(!value) return "RM0.00";

  const clean = String(value).trim();

  if(clean.toUpperCase().includes("RM")){
    return clean;
  }

  return "RM" + clean;
}

function getNumberOnly(value){
  if(!value) return 0;

  return Number(String(value).replace(/[^\d.]/g, "")) || 0;
}
