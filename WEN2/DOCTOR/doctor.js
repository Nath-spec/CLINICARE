// === Global Chat Variables ===
let chatBox;
let chatInput;
let chatForm;
let receiverInput;
let messagePolling = null; 
let currentlyLoading = false; // Flag to prevent multiple simultaneous loads

// === Message Loading Function (Global) ===
window.loadMessages = async function() {
  // Prevent loading if already loading or no recipient selected
  if (currentlyLoading || typeof CURRENT_USER_ID === 'undefined' || !receiverInput || !receiverInput.value || receiverInput.value === 'null' || receiverInput.value === '') {
      return; 
  }

  currentlyLoading = true; // Set loading flag
  const other_id = receiverInput.value;
  
  try {
    const res = await fetch(`../get_messages.php?other_id=${other_id}`); // Use relative path
    
    if (!res.ok) {
        throw new Error(`Failed to fetch messages. Status: ${res.status} ${res.statusText}`);
    }
    
    const messages = await res.json();
    
    const isScrolledToBottom = chatBox.scrollHeight - chatBox.clientHeight <= chatBox.scrollTop + 50;
    
    chatBox.innerHTML = ""; // Clear existing messages

    if (messages.length === 0) {
      chatBox.innerHTML = `<div style="text-align:center;color:var(--muted); padding: 50px 20px;">No messages yet. Start the conversation!</div>`;
    } else {
      messages.forEach(msg => {
        const div = document.createElement("div");
        const senderId = Number(msg.sender_id); 
        
        div.className = senderId === CURRENT_USER_ID ? "msg outgoing" : "msg incoming"; 
        
        let timeString = 'Sending...';
        try {
            const timestampValue = msg.timestamp || msg.created_at; 
            if (timestampValue) {
                // MySQL timestamps need 'T' and 'Z' to be parsed correctly as UTC
                const time = new Date(timestampValue.replace(' ', 'T') + 'Z'); 
                 if (!isNaN(time.getTime())) { 
                    timeString = time.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit', hour12: true });
                 } else {
                     timeString = "--:-- --"; // Fallback for invalid date
                 }
            } else {
                 timeString = "--:-- --"; // Fallback for missing timestamp
            }
        } catch(dateError) {
             timeString = "--:-- --";
        }

        // Use textContent to prevent XSS from message content
        const msgTextDiv = document.createElement("div");
        // 🔴 FIX: Inalis ang maling class name na 'msg.message_text'
        // msgTextDiv.className = "msg-text"; // Pwede ito kung may CSS ka para dito
        msgTextDiv.textContent = msg.message; // Safer way to display message

        const msgTimeDiv = document.createElement("small"); // Use small tag for timestamp
        msgTimeDiv.textContent = timeString;

        div.appendChild(msgTextDiv);
        div.appendChild(msgTimeDiv);
        chatBox.appendChild(div);
      });
      
      if (isScrolledToBottom) {
           setTimeout(() => { chatBox.scrollTop = chatBox.scrollHeight; }, 0);
      }
    }
  } catch (err) {
    console.error("Error loading messages:", err);
    chatBox.innerHTML = `<div style="text-align:center;color:red; padding: 50px 20px;">Error loading messages. Please check connection or try again later.</div>`;
  } finally {
      currentlyLoading = false; // Reset loading flag
  }
}

// === Recipient Selection Function (Global) ===
window.selectRecipient = (element, recipientId, recipientName) => {
  document.querySelectorAll(".chat-list .chat-item").forEach(item => item.classList.remove("active"));
  element.classList.add("active");
  
  document.getElementById("chatWith").textContent = recipientName; 
  receiverInput.value = recipientId;
  
  // Enable form
  chatForm.style.display = 'flex';
  chatInput.disabled = false;
  chatForm.querySelector('button').disabled = false;

  if (messagePolling) {
      clearInterval(messagePolling);
  }
  
  chatBox.innerHTML = '<div style="text-align:center;color:var(--muted); padding: 50px 20px;">Loading messages...</div>'; 
  window.loadMessages().then(() => {
      messagePolling = setInterval(window.loadMessages, 3000); 
  });
  
  const url = new URL(window.location);
  url.searchParams.set('patient_id', recipientId);
  window.history.pushState({}, '', url);
}

// === Initialization and Event Listeners ===
document.addEventListener("DOMContentLoaded", () => {
  
  // === PROFILE DROPDOWN LOGIC ===
  const profileMenu = document.getElementById("profileToggle");
  const dropdown = document.getElementById("profileDropdown");
  const caret = profileMenu ? profileMenu.querySelector(".caret") : null;

  if (profileMenu && dropdown && caret) {
      profileMenu.addEventListener("click", (e) => {
          e.stopPropagation();
          dropdown.classList.toggle("show");
          caret.style.transform = dropdown.classList.contains("show") 
              ? "rotate(180deg)" 
              : "rotate(0deg)";
      });

      document.addEventListener("click", (e) => {
          if (profileMenu && !profileMenu.contains(e.target)) {
              dropdown.classList.remove("show");
              caret.style.transform = "rotate(0deg)";
          }
      });
  }

  // === CHAT APPLICATION Initialization ===
  chatBox = document.getElementById("chatBox");
  chatInput = document.getElementById("chatInput");
  chatForm = document.getElementById("chatForm");
  receiverInput = document.getElementById("recipientId");

  if (typeof CURRENT_USER_ID === 'undefined' || !chatBox || !chatForm || !receiverInput) {
      console.error("Critical chat elements (or CURRENT_USER_ID) not found. Check doctor_chat.php HTML IDs and PHP constants.");
      if(chatBox) chatBox.innerHTML = "<div style='color:red; text-align:center; padding: 50px 20px;'>Chat initialization failed. Contact support.</div>";
      return;
  }
  
  // === Message Sending Event Listener ===
  chatForm.addEventListener("submit", async e => {
    e.preventDefault();
    const receiver_id = receiverInput.value;
    const message = chatInput.value.trim();
    
    if (!message || !receiver_id || receiver_id === 'null' || receiver_id === '') {
        return;
    }

    const now = new Date();
    const timeString = now.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit', hour12: true });
    const originalMessage = message; 
    
    // --- Optimistic UI Update ---
    const tempDiv = document.createElement("div");
    tempDiv.className = "msg outgoing";
    
    const msgTextDiv = document.createElement("div");
    // 🔴 FIX: Inalis ang maling class name na 'msg.message_text'
    msgTextDiv.textContent = originalMessage; 

    const msgTimeDiv = document.createElement("small"); 
    msgTimeDiv.textContent = timeString + " (Sending...)"; 

    tempDiv.appendChild(msgTextDiv);
    tempDiv.appendChild(msgTimeDiv);

    const placeholder = chatBox.querySelector('div[style*="text-align:center"]');
    if (placeholder) {
        chatBox.innerHTML = ''; 
    }
    
    chatBox.appendChild(tempDiv);
    chatBox.scrollTop = chatBox.scrollHeight;
    chatInput.value = ""; 
    // --- End Optimistic Update ---

    try {
      const res = await fetch("../send_message.php", { // Ensure correct path
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ receiver_id: receiver_id, message: originalMessage })
      });
      
      if (!res.ok) {
          throw new Error(`Server responded with status: ${res.status}`);
      }

      const data = await res.json(); 

      if (data.success) { 
         msgTimeDiv.textContent = timeString; 
         setTimeout(window.loadMessages, 500); 
      } else {
        console.error("Failed to send message:", data.error);
        msgTimeDiv.textContent = timeString + " (Failed)";
        msgTimeDiv.style.color = "red";
      }
    } catch (error) {
      console.error("Connection Error:", error);
      msgTimeDiv.textContent = timeString + " (Connection Error)"; 
      msgTimeDiv.style.color = "orange";
    }
  });

  // === Initial Load & Polling ===
  const initialRecipientId = receiverInput.value;
  if (initialRecipientId && initialRecipientId !== 'null' && initialRecipientId !== '') {
    window.loadMessages(); // Load initial messages
    messagePolling = setInterval(window.loadMessages, 3000); 
  } else {
    chatBox.innerHTML = "<div style='text-align:center;color:var(--muted); padding: 50px 20px;'>Please select a patient from the list to start chatting.</div>";
    // Disable form if no recipient
    chatForm.style.display = 'none';
    chatInput.disabled = true;
    chatForm.querySelector('button').disabled = true;
  }
});

